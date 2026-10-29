<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\ai\AiAnalysisService;
use tommyknocker\pdodb\ai\AiQueryOptions;
use tommyknocker\pdodb\ai\QueryAdvicePrompt;
use tommyknocker\pdodb\ai\QueryContextBuilder;
use tommyknocker\pdodb\ai\QueryMetadataCollector;
use tommyknocker\pdodb\ai\SqlContextTokenizer;
use tommyknocker\pdodb\ai\StructuredQueryAdvice;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\analysis\AiExplainAnalysis;
use tommyknocker\pdodb\query\analysis\ExplainAnalysis;
use tommyknocker\pdodb\query\analysis\ParsedExplainPlan;

final class AiQueryAdviceTests extends TestCase
{
    private function database(string $prefix = ''): PdoDb
    {
        $db = new PdoDb('sqlite', ['path' => ':memory:', 'prefix' => $prefix, 'ai' => [
            'provider' => 'openrouter', 'openrouter_key' => 'example-key',
            'providers' => ['openrouter' => ['model' => 'test/model']],
        ]]);
        $db->rawQuery('CREATE TABLE ' . $prefix . 'users (id INTEGER PRIMARY KEY, email TEXT UNIQUE)');
        $db->rawQuery('CREATE TABLE ' . $prefix . 'orders (id INTEGER PRIMARY KEY, user_id INTEGER REFERENCES ' . $prefix . "users(id), status TEXT DEFAULT 'private-default', total INTEGER)");
        $db->rawQuery('CREATE INDEX idx_status_total ON ' . $prefix . 'orders(status, total DESC)');
        return $db;
    }

    public function testPreviewResolvesJoinsAliasesPrefixesWithoutLlm(): void
    {
        $db = $this->database('app_');
        $query = $db->find()->from('orders o')->join('users u', 'o.user_id = u.id')->where('u.email', 'private-email');
        $context = $query->explainAiContext();
        self::assertSame(['main.app_orders', 'main.app_users'], array_keys($context['schema']['tables']));
        self::assertSame('email', $context['schema']['tables']['main.app_users']['columns'][1]['name']);
        self::assertSame(['status', 'total'], array_column($context['schema']['tables']['main.app_orders']['indexes'][0]['columns'], 'name'));
        self::assertTrue($context['schema']['tables']['main.app_orders']['indexes'][0]['columns'][1]['descending']);
        self::assertTrue($context['collection']['complete']);
        self::assertNull($context['explain_plan']['facts']['estimated_rows']);
        self::assertSame('database', $context['explain_plan']['source']);
        self::assertStringNotContainsString('private-email', json_encode($context));
        self::assertStringNotContainsString('private-default', json_encode($context));
        self::assertSame(['string'], array_values($context['parameter_types']));
        self::assertNotEmpty($context['database']['version']);
    }

    public function testRealSqlitePlanDistinguishesIndexAndScan(): void
    {
        $db = $this->database();
        $indexed = $db->find()->from('orders')->where('status', 'pending')->explainAiContext();
        self::assertSame('idx_status_total', $indexed['explain_plan']['facts']['used_index']);
        $scan = $db->find()->from('orders')->where('user_id', 1)->explainAiContext();
        self::assertContains('orders', $scan['explain_plan']['facts']['table_scans']);
        self::assertNull($scan['explain_plan']['facts']['used_index']);
        // Preserve the existing public raw EXPLAIN bytecode contract.
        self::assertArrayHasKey('opcode', $db->find()->from('orders')->explain()[0]);
    }

    public function testPartialExpressionIndexesAndQualifiedNames(): void
    {
        $db = $this->database();
        $db->rawQuery("CREATE INDEX idx_partial ON orders(lower(status)) WHERE total > 25 AND status <> 'private-predicate'");
        $context = (new AiAnalysisService($db))->previewQueryContext('SELECT * FROM main.orders o');
        $indexes = $context['schema']['tables']['main.orders']['indexes'];
        $partial = array_values(array_filter($indexes, static fn ($i) => $i['name'] === 'idx_partial'))[0];
        self::assertTrue($partial['partial']);
        self::assertNotNull($partial['columns'][0]['expression']);
        self::assertStringNotContainsString('private-predicate', $partial['definition']);
        self::assertStringContainsString('lower', $partial['definition']);
    }

    public function testCatalogFailuresDoNotDiscardCollectedColumns(): void
    {
        $db = $this->database();
        $collector = new QueryMetadataCollector($db, (new AiQueryOptions())->context);
        $missing = $collector->collect('missing');
        self::assertFalse($missing['complete']);
        self::assertNotEmpty($missing['warnings']);
        $service = new AiAnalysisService($db);
        $context = $service->previewQueryContext('SELECT 1', options: ['context' => ['tables' => ['orders', 'missing']]]);
        self::assertNotEmpty($context['schema']['tables']['main.orders']['columns']);
        self::assertFalse($context['collection']['complete']);
    }

    public function testLimitsAreExplicitAndBoundTheActualPayload(): void
    {
        $db = $this->database();
        $context = $db->find()->from('orders')->join('users', 'orders.user_id = users.id')->explainAiContext(options: ['context' => ['max_tables' => 1, 'max_columns' => 2]]);
        self::assertCount(1, $context['schema']['tables']);
        self::assertCount(2, $context['schema']['tables']['main.orders']['columns']);
        self::assertFalse($context['collection']['complete']);
        self::assertContains('Table limit reached', $context['collection']['warnings']);
        $small = $db->find()->from('orders')->explainAiContext(options: ['context' => ['max_bytes' => 2048, 'goal' => str_repeat('g', 1900)]]);
        self::assertLessThanOrEqual(2048, strlen(json_encode($small, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        self::assertTrue($small['collection']['payload_truncated']);
        self::assertFalse($small['collection']['complete']);
    }

    public function testExplicitTablesAndOptionalStatistics(): void
    {
        $db = $this->database();
        $context = (new AiAnalysisService($db))->previewQueryContext('WITH report AS (SELECT * FROM orders) SELECT * FROM report', options: ['context' => ['tables' => ['users'], 'include_statistics' => true]]);
        self::assertSame(['main.users'], array_keys($context['schema']['tables']));
        self::assertSame('explicit_physical_tables', $context['collection']['discovery']);
        self::assertFalse($context['schema']['tables']['main.users']['statistics']['exact']);
        self::assertSame(['orders'], SqlContextTokenizer::tables('WITH report AS (SELECT * FROM orders) SELECT * FROM report'));
    }

    public function testLexicalRedactionHandlesQuotesCommentsAndDollarStrings(): void
    {
        $sql = <<<'SQL'
SELECT "public"."users" FROM "public"."users" WHERE email = 'private''email' AND note = $tag$private-dollar$tag$ AND x = q'[private-oracle]' AND n = 42 /* private-comment */ -- another-secret
SQL;
        $redacted = SqlContextTokenizer::redact($sql);
        foreach (['private', 'another-secret', '42'] as $secret) {
            self::assertStringNotContainsString($secret, $redacted);
        }
        self::assertStringContainsString('"public" . "users"', $redacted);
        self::assertSame(['"public"."users"'], SqlContextTokenizer::tables($sql));
    }

    public function testRawPlanAndDefaultsRequireExplicitDisclosure(): void
    {
        $db = $this->database();
        $context = $db->find()->from('orders')->explainAiContext(options: ['context' => ['privacy' => 'raw', 'include_raw_plan' => true]]);
        self::assertArrayHasKey('raw_explain', $context['explain_plan']);
        self::assertStringContainsString('private-default', json_encode($context));
        $this->expectException(InvalidArgumentException::class);
        new AiQueryOptions(['context' => ['include_raw_plan' => true]]);
    }

    public function testUnsupportedPlansAreNotPromotedToEvidence(): void
    {
        $db = new PdoDb();
        $db->addConnection('synthetic', ['driver' => 'sqlsrv', 'pdo' => $this->database()->connection->getPdo(), 'enable_regexp' => false]);
        $db->connection('synthetic');
        $context = (new QueryContextBuilder($db))->build('SELECT 1', null, new ExplainAnalysis([['Rows' => 0]], new ParsedExplainPlan()), ['context' => ['tables' => []]]);
        self::assertSame('unavailable', $context['explain_plan']['source']);
        self::assertSame([], $context['explain_plan']['facts']);
    }

    public function testStructuredAdviceValidatesEvidenceAndKeepsLegacyResult(): void
    {
        $context = $this->database()->find()->from('orders')->explainAiContext();
        $finding = ['severity' => 'info', 'type' => 'index_usage', 'message' => 'Review scan', 'evidence' => ['/explain_plan/nodes/0/detail'], 'suggestion' => null, 'limitations' => ['Estimated plan only']];
        $parsed = StructuredQueryAdvice::parse(json_encode(['summary' => 'Review', 'findings' => [$finding]]), $context);
        self::assertNotNull($parsed);
        $result = new AiExplainAnalysis(new ExplainAnalysis([], new ParsedExplainPlan()), 'Original text', 'openrouter', structuredAdvice: $parsed);
        self::assertSame('Original text', $result->getAiAnalysis());
        self::assertCount(1, $result->getAiRecommendations());
        self::assertTrue($result->hasRecommendations());
        self::assertCount(1, $result->getAllRecommendations());
        $finding['evidence'] = ['/explain_plan/facts/invented'];
        self::assertNull(StructuredQueryAdvice::parse(json_encode(['summary' => 'Review', 'findings' => [$finding]]), $context));
        self::assertNull(StructuredQueryAdvice::parse('plain text', $context));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testActualProviderPayloadMatchesPreviewAndUsesOneCall(): void
    {
        require __DIR__ . '/../fixtures/ai-http-functions.php';
        $db = $this->database();
        $query = $db->find()->from('orders o')->join('users u', 'o.user_id = u.id')->where('u.email', 'private-email');
        $options = ['context' => ['goal' => 'Fast pagination', 'constraints' => ['No new indexes'], 'language' => 'Russian']];
        $preview = $query->explainAiContext(options: $options);
        self::assertEmpty($GLOBALS['ai_test_requests'] ?? []);
        $result = $query->explainAiAdvice(options: $options);
        self::assertSame('ok', $result->status);
        self::assertSame($preview, $result->context);
        self::assertCount(1, $GLOBALS['ai_test_requests']);
        $body = json_decode($GLOBALS['ai_test_requests'][0]['options']['http']['content'], true);
        self::assertSame(QueryAdvicePrompt::user($preview['sql'], $preview), $body['messages'][1]['content']);
        self::assertSame(QueryAdvicePrompt::system(), $body['messages'][0]['content']);
        self::assertStringContainsString('main.users', $body['messages'][1]['content']);
        self::assertStringNotContainsString('private-email', $body['messages'][1]['content']);
        $invalid = $query->explainAiAdvice(options: ['structured' => true]);
        self::assertSame('invalid_response', $invalid->status);
        self::assertSame('Test analysis', $invalid->aiAnalysis);
    }
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStructuredSuccessAndProviderFailureStatuses(): void
    {
        require __DIR__ . '/../fixtures/ai-http-functions.php';
        $query = $this->database()->find()->from('orders');
        $result = $query->explainAiAdvice(options: ['structured' => true, 'model' => 'test/structured-advice']);
        self::assertSame('ok', $result->status);
        self::assertSame('Review estimated scan', $result->structuredAdvice['summary']);
        self::assertCount(1, $result->getAiRecommendations());
        self::assertTrue($result->hasRecommendations());
        self::assertSame('test/structured-advice', $result->model);
        $failure = $query->explainAiAdvice(options: ['model' => 'test/reasoning-budget']);
        self::assertSame('ai_unavailable', $failure->status);
        self::assertStringStartsWith('AI analysis unavailable:', $failure->aiAnalysis);
        self::assertNotEmpty($failure->baseAnalysis->rawExplain);
        self::assertStringNotContainsString('test-only', $failure->aiAnalysis);
    }

    public function testCatalogQueryBudgetAndPlanRowBudgetAreVisible(): void
    {
        $context = $this->database()->find()->from('orders')->explainAiContext(options: ['context' => ['max_catalog_queries' => 1]]);
        self::assertSame(1, $context['collection']['catalog_queries']);
        self::assertFalse($context['collection']['complete']);
        self::assertNotEmpty($context['schema']['tables']['main.orders']['columns']);
        $db = $this->database();
        $plan = new ExplainAnalysis([['detail' => 'SCAN orders'], ['detail' => 'SEARCH users USING INTEGER PRIMARY KEY (rowid=?)']], new ParsedExplainPlan());
        $context = (new QueryContextBuilder($db))->build('SELECT * FROM orders', null, $plan, ['context' => ['max_plan_rows' => 1]]);
        self::assertTrue($context['explain_plan']['truncated']);
        self::assertFalse($context['collection']['complete']);
    }

    public function testAllProvidersShareEvidenceInstructions(): void
    {
        $classes = ['OpenAiProvider', 'OpenAiCompatibleProvider', 'AnthropicProvider', 'GoogleProvider', 'MicrosoftProvider', 'DeepSeekProvider', 'YandexProvider', 'OllamaProvider'];
        foreach ($classes as $name) {
            $class = 'tommyknocker\\pdodb\\ai\\providers\\' . $name;
            $provider = new $class();
            self::assertSame(QueryAdvicePrompt::system(), (new \ReflectionMethod($class, 'buildSystemPrompt'))->invoke($provider, 'query'));
            self::assertSame(QueryAdvicePrompt::user('SELECT 1', []), (new \ReflectionMethod($class, 'buildQueryPrompt'))->invoke($provider, 'SELECT 1', []));
        }
        self::assertSame(['orders', 'users'], SqlContextTokenizer::tables('SELECT o.id, u.email FROM orders o, users u WHERE o.user_id = u.id'));
    }
}
