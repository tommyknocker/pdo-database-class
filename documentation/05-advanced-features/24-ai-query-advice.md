# Explain and improve a query with explainAiAdvice()

Start with [three runnable examples with real AI answers and measured plans](25-ai-query-advice-in-practice.md).
This page covers the API, context controls and result handling in detail.

Use `explainAiAdvice()` when you have a PDOdb SELECT query and want help
understanding its execution plan, index use, or a possible rewrite. Typical
situations include investigating a slow endpoint, reviewing a new JOIN, or
checking an ordered/paginated query before deployment.

The method collects database evidence and makes **one request** to your configured
AI provider. You do not need MCP, tools, an agent loop, or a daemon. The enhanced
context, preview and structured results described here require PDOdb 2.14.0 or newer.

## 1. Configure the provider

For OpenRouter, set these in the environment of your PHP application:

```dotenv
PDODB_AI_PROVIDER=openrouter
PDODB_AI_OPENROUTER_KEY=your-private-key
PDODB_AI_OPENROUTER_MODEL=your-provider/model-id
```

Use an actual model ID available to your account. Keep the real key in your
local, untracked environment or secret store. PDOdb also supports other
[providers and compatible endpoints](23-ai-analysis.md#configuration).
The dedicated `.env.ai.local` file is loaded by the repository's live-test
runner and the showcase with `--ai`, not automatically by this PHP method.

This guide assumes `$db` is your application's configured `PdoDb` instance and
`orders` contains `id`, `user_id`, `status`, and `created_at`.

## 2. Start with the query you want to investigate

```php
$query = $db->find()
    ->from('orders o')
    ->join('users u', 'o.user_id = u.id')
    ->where('o.status', 'pending')
    ->orderBy('o.created_at', 'DESC')
    ->limit(50);

$advice = $query->explainAiAdvice(options: [
    'max_tokens' => 2000,
    'timeout' => 30, // AI HTTP request timeout, in seconds
    'context' => [
        'goal' => 'Reduce latency of the first page of pending orders',
        'constraints' => ['Preserve ordering and result semantics', 'The orders table is write-heavy'],
        'language' => 'English',
    ],
]);

echo $advice->aiAnalysis;
```

The assistant receives the schemas of both tables, existing indexes and their
column order, foreign keys, the database version, and available estimated-plan
evidence. It can explain a scan/sort and whether an existing index is relevant.
The prompt asks it to separate observations from hypotheses and describe
tradeoffs and verification steps.

An answer might propose evaluating an index on `(status, created_at)` if the
supplied plan and existing indexes support that recommendation. That is a
hypothesis to test on your workload, not a promise of a particular speedup.
The method never creates the index or runs the SELECT to return application rows.

Call it during development, diagnostics, or an explicit administrative action.
It performs catalog/EXPLAIN work and a network request, so avoid calling it on
every normal application request.

## 3. Preview what will be sent, without calling a model

```php
$options = [
    'context' => [
        'goal' => 'Investigate sorting for this page',
        'max_tables' => 8,
        'max_bytes' => 32768,
    ],
];

$context = $query->explainAiContext(options: $options);
// Inspect locally; avoid writing private database metadata to public logs.
$tables = array_keys($context['schema']['tables']);
$planSource = $context['explain_plan']['source'];
$complete = $context['collection']['complete'];

$advice = $query->explainAiAdvice(options: $options);
$sentContext = $advice->context;
```

Preview needs database access but no provider key. It runs its own EXPLAIN and
metadata collection. A subsequent advice call collects fresh evidence, so a
schema/plan change between the two calls can change the payload. The advice
result's `context` is the exact bounded context used for that model request.
`explainAiContext()` is a convenience method on the bundled concrete builders;
existing third-party builder interfaces have not gained a required method.

For an already collected context, `AiAnalysisService::analyzeQueryContext()`
sends it directly. This advanced API trusts the caller's disclosure policy;
normal calls should use the preview/advice collection APIs.

## 4. Read status and limitations before using the advice

```php
if ($advice->status === 'ai_unavailable') {
    // Provider/configuration/network/output-budget failure.
    // The base database analysis and collected context are still available.
}

if ($advice->planSource === 'unavailable') {
    // The model can discuss SQL/schema, but has no verified execution plan.
}

foreach ($advice->warnings as $warning) {
    // Show plan-availability or response-format limitations in your UI.
}

$base = $advice->baseAnalysis;  // Existing ExplainAnalysis contract
$provider = $advice->provider;
$model = $advice->model;
```

The method preserves the original arguments, `AiExplainAnalysis` return type,
`aiAnalysis` text and `baseAnalysis`. Additive fields include `status`,
`planSource`, `warnings`, `context`, and optional `structuredAdvice`.
Invalid context options raise `InvalidArgumentException` before an AI request.
Provider failures produce a generic error message rather than embedding raw
provider response bodies in the report.

| Database | Evidence collected by this method |
| --- | --- |
| MySQL / MariaDB | Estimated EXPLAIN with bound parameters |
| PostgreSQL | Estimated EXPLAIN with bound parameters; structural nodes and available cost/row estimates |
| SQLite | EXPLAIN QUERY PLAN with real SCAN/SEARCH/index information; no fabricated row estimates |
| MSSQL | Plan explicitly unavailable with this adapter; SQL/schema advice only |
| Oracle | EXPLAIN PLAN for parameter-free SQL; parameterized plans explicitly unavailable, without interpolating values |

Oracle EXPLAIN PLAN uses the service PLAN_TABLE and requires corresponding
permissions. This method never switches to EXPLAIN ANALYZE. Estimated cost is
not measured latency; SQL/schema advice without a plan is explicitly labelled.
The existing public `explain()` method retains its raw dialect-specific output.

## 5. Control context size and collection work

All context options go under `$options['context']`; provider generation settings
such as `model`, `max_tokens`, `temperature`, and `timeout` remain at the top level.

| Option | Default | Meaning |
| --- | --- | --- |
| `tables` | `null` | Discover FROM/JOIN references. An explicit list replaces discovery and names physical tables, including prefixes/schema when applicable. `[]` disables schema collection. |
| `max_tables` | `8` | Maximum tables collected (0–50) |
| `max_columns` | `100` | Columns per table and bounded index-column/FK collection (1–1000) |
| `max_indexes` | `50` | Indexes per table (1–200) |
| `max_plan_rows` | `100` | Plan rows supplied in context (1–500) |
| `max_bytes` | `32768` | Maximum serialized context bytes (2048–262144); prompt instructions add a small overhead |
| `max_catalog_queries` | `64` | Maximum catalog statements per collection (1–512) |
| `max_collection_ms` | `1000` | Budget checked between catalog statements (1–30000) |
| `include_statistics` | `false` | Opt into approximate catalog row counts/available freshness metadata |
| `privacy` | `'redacted'` | Lexically remove SQL literals/comments, omit column defaults and raw plan text. `'raw'` explicitly permits those values. |
| `include_raw_plan` | `false` | Include raw plan rows; requires `privacy => 'raw'` |
| `goal` | `''` | Task description, up to 2000 bytes |
| `constraints` | `[]` | Up to 50 bounded strings describing workload/implementation constraints |
| `language` | `''` | Requested response language, up to 100 bytes |

Collection budgets are **not database statement timeouts**. They do not interrupt
an already running catalog statement or EXPLAIN. Apply database-side limits
where needed; `timeout` controls the AI HTTP request only.

When limits remove evidence, `collection.complete` becomes false; check its
warnings, `payload_truncated`, per-table warnings and the plan's `truncated` flag.
A failed metadata component leaves successfully collected components available.
Byte limits may remove entire table entries or plan sections. There is no
implicit COUNT(*), table sampling, or traversal of every table in the database.

Explicit physical tables help with CTEs, raw SQL, derived tables or ambiguous
references. Discovery is lexical and best effort, not a complete SQL parser:

```php
$advice = $query->explainAiAdvice(options: [
    'context' => [
        'tables' => ['app_orders', 'app_users'],
        'include_statistics' => true,
    ],
]);
```

Statistics remain approximate, carry their catalog source, and expose freshness
when available. A missing estimate stays unknown. Index definitions preserve
available expressions/predicates; unsupported attributes remain unknown. Oracle hidden index columns are accompanied by available catalog expressions.

## 6. Decide what may leave your application

By default, actual binding values are used locally for EXPLAIN but are **not**
passed as an explicit parameter-value array to the model. Their PHP types are
included. SQL literals and comments are lexically redacted, column defaults are
omitted, and raw plans/predicates that could embed values are excluded. Structural
plan facts, table/index names and schema still go to the configured provider. Redacted values can prevent proving
that a partial-index predicate matches the query; the prompt requires the model
to acknowledge that uncertainty.

This is not guaranteed anonymization: SQL dialect extensions, quoted strings
that resemble identifiers, names, user-supplied goals/constraints, and database
metadata can disclose information. Preview the context and choose a suitable
provider; an internally hosted compatible endpoint is an option.

For an intentional diagnostic session needing full literals/defaults and raw
plan text:

```php
$context = $query->explainAiContext(options: [
    'context' => ['privacy' => 'raw', 'include_raw_plan' => true],
]);
```

The preview itself makes no AI request. Sending the same options to
`explainAiAdvice()` permits those values to reach the provider.

## 7. Optional structured advice for a report/UI

```php
$advice = $query->explainAiAdvice(options: [
    'structured' => true,
    'max_tokens' => 2000,
    'context' => ['language' => 'English'],
]);

if ($advice->status === 'ok' && $advice->structuredAdvice !== null) {
    $summary = $advice->structuredAdvice['summary'];
    foreach ($advice->structuredAdvice['findings'] as $finding) {
        // severity, type, message, evidence, suggestion, limitations
        // evidence contains JSON Pointer paths into $advice->context.
    }
}
```

Structured mode asks for JSON in the shared prompt and validates field types,
size, severity and evidence pointers. It does not require a provider-specific
JSON-mode API. Evidence pointers must resolve to supplied plan facts/nodes or
schema metadata; this validates references, not the truth of the interpretation.

If the model returns malformed JSON or invalid pointers, status is
`invalid_response`, `structuredAdvice` stays null, and `aiAnalysis` retains the
original response for review. There is no automatic retry or second paid call.
An empty findings list is valid when evidence is insufficient. Give the model
enough output tokens for the requested format; a 512-token smoke-test budget
may be too small for a detailed report.

`getAiRecommendations()` exposes validated findings as `Recommendation` objects;
`getAllRecommendations()` combines them with library-rule recommendations. Plain
text AI advice is not silently parsed into recommendations. Suggested SQL is
always advisory and is never executed automatically.

## Verify a proposed optimization

Keep query semantics and representative workload fixed. Compare the plan before
and after a candidate change, measure real latency separately, and assess storage
and write overhead. Do not use a successful AI response as proof of improvement.
For repeatable provider testing and private credentials, see the
[testing guide](../../tests/README.md).
