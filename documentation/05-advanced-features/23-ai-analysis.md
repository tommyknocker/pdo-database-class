# Understand and improve a query with AI

You know which query makes an endpoint slow. The next question is what to change:
its filter, a JOIN, an index, or nothing at all. PDOdb can explain that query
using the database's own plan and existing schema, directly from PHP.
With an existing PDOdb connection in `$db`, the call looks like this:

```php
$advice = $db->find()
    ->from('orders')
    ->where('status', 'pending')
    ->orderBy('created_at', 'DESC')
    ->limit(50)
    ->explainAiAdvice();

echo $advice->aiAnalysis;
```

The model receives the plan, columns and indexes for the tables involved. You
get an explanation and candidate changes to review. PDOdb does not create
indexes or apply the model's SQL.

## What a useful answer looks like

On our example orders page, SQLite reported:

```text
SCAN orders
USE TEMP B-TREE FOR ORDER BY
```

The captured AI answer identified a candidate index:

> An index on `(status, created_at DESC)` could let the engine seek to the
> `status` value and read `created_at` in order, avoiding the temp B-tree for page one.

After we applied that index ourselves in the test fixture, the plan became:

```text
SEARCH orders USING INDEX idx_orders_status_created (status=?)
```

The same 50 rows came back in the same order. On that synthetic SQLite fixture,
the median SELECT time changed from **4.6860 ms to 0.0200 ms**. This is a local
measurement, not an AI prediction or a promised production speedup.

The point is the connection between **your query, actual database evidence, and
a change you can verify**. The model does not have to guess whether an index
exists or ask you to paste a schema.

## Try it before connecting your own database

The repository includes a complete demo with 100,000 synthetic orders and 2,000
customers. Use PHP 8.4+ with `pdo_sqlite`, then from a PDOdb 2.14+ checkout run:

```bash
composer install
php examples/07-performance/05-ai-query-advice.php
```

It creates a fresh database in memory, shows real plans and timings, and checks
that each index change preserves the result. This first run is offline: it
needs no key and does not use your application's database.

The demo covers three different decisions:

| Your task | What to look for |
| --- | --- |
| Show the newest pending orders | Can one index serve the filter and the requested order? |
| Report orders for a small customer group | Does the JOIN start with a full scan when indexed lookups would help? |
| Review recent orders for one customer | Does an existing composite index already do the job? |

[See the full examples, captured answers and before/after results](25-ai-query-advice-in-practice.md).

## Configuration

**Choose one provider.** You need its key and a model; you do not need keys for
other services or a list of generation parameters.

Here is the OpenRouter setup for the demo. Create a local `.env.ai.local` file
in the repository root with these three values:

```dotenv
PDODB_AI_PROVIDER=openrouter
PDODB_AI_OPENROUTER_KEY=your-openrouter-key
PDODB_AI_OPENROUTER_MODEL=openai/gpt-4o-mini
```

The model shown is the library's default OpenRouter model. You can replace it
with a text model available to your account. The endpoint, timeout and generation
settings already have defaults. Keep the real key out of Git.

Run the same demo with AI enabled:

```bash
php examples/07-performance/05-ai-query-advice.php --ai
```

Now each query gets its own live answer alongside the plan. The command reports
progress for three requests, with at most 512 output tokens per answer. The
fixture still lives only in memory. The printed report includes each model
answer, the database plans and the before/after result checks.

Already use another provider? The [provider reference](26-ai-reference.md#configuration)
shows only what each service requires, including local Ollama and custom
OpenAI-compatible endpoints. Those alternatives are optional; they are not
additional setup steps for OpenRouter.

## Use the same call in your application

Set the same three provider variables in the environment of your PHP process,
or in the `.env` file your application already loads. The demo's `.env.ai.local`
is specific to the demo/live-test scripts; a normal PHP method does not load it.
Keep your existing database connection and QueryBuilder code.

```php
$query = $db->find()
    ->from('orders')
    ->where('status', 'pending')
    ->orderBy('created_at', 'DESC')
    ->limit(50);

$advice = $query->explainAiAdvice();

if ($advice->status === 'ok') {
    echo $advice->aiAnalysis;
} else {
    // The base plan and context are still available when the provider fails.
    echo 'AI advice is unavailable; use the collected database analysis.';
}
```

For a JOIN, PDOdb collects the participating tables' metadata as well. Aliases
and configured table prefixes are resolved. For complex raw SQL, you can supply
an explicit physical-table list through the advanced context options.

One advice call makes one model request. Use it during development, an explicit
diagnostic session, or an administrative action; it adds database and network
work, so it does not belong in every normal request handler.

## Tell it what matters for this query

Start with the basic call. Add a goal only when the workload changes what a
useful recommendation would be:

```php
$advice = $query->explainAiAdvice(options: [
    'context' => [
        'goal' => 'Reduce latency for the first page of pending orders',
        'constraints' => ['Preserve result order', 'This table receives frequent writes'],
        'language' => 'English',
    ],
]);
```

This tells the model to consider the write/storage cost of an index alongside
read performance. A recommendation to keep an existing suitable index can be
just as useful as a new index suggestion. The
[third example](25-ai-query-advice-in-practice.md#3-recent-orders-for-one-customer)
shows an actual response making that decision.

## Check the context before sending it

```php
$context = $query->explainAiContext(); // Database work, no model call
```

The context contains the SQL shape, available plan evidence, database version,
and schema/index metadata. By default, explicit binding values are omitted,
SQL literals/comments are lexically redacted, and raw plan text and column
defaults are excluded. Names and schema information still reach the provider;
this is not guaranteed anonymization. Preview the context when disclosure matters.

The result's `context` is the bounded context used for that request. A missing
plan, failed metadata collection or truncation is reported rather than invented.
`baseAnalysis` remains available alongside the AI explanation. The
[API guide](24-ai-query-advice.md) describes the fields and privacy controls.

## Review and verify the recommendation

Compare the suggested columns and their order with existing indexes. Try a
candidate change on a test copy, collect a new plan, and measure the actual
SELECT separately. Check that the returned rows and ordering still match.

The model sees estimated plans, not production latency. It may miss a tradeoff
or make an incorrect claim; the plan and measured result give you something to
check. In the demo, all index changes come from fixed, reviewed example code,
not executable model output.

The current MSSQL adapter reports its plan as unavailable. Parameterized Oracle
EXPLAIN is also unavailable; those requests can receive SQL/schema advice but
are labelled as lacking a verified plan. See the
[adapter details](24-ai-query-advice.md#4-read-status-and-limitations-before-using-the-advice).

## Where to go next

| You want to… | Continue with… |
| --- | --- |
| Inspect complete answers and reproduce the changes | [Three measured examples](25-ai-query-advice-in-practice.md) |
| Build a report/UI or tune context and output limits | [Query-advice API guide](24-ai-query-advice.md) |
| Use another provider or a custom API endpoint | [Configuration reference](26-ai-reference.md#configuration) |
| Analyze schema or use CLI reports | [Service and CLI reference](26-ai-reference.md#cli-commands) |
| Let an editor inspect your development database | [Optional MCP walkthrough](../01-getting-started/08-ai-editor.md) |

## MCP Server

MCP is a separate, optional way for an editor's assistant to retrieve database
facts. It is not part of the PHP advice call above. See the
[editor walkthrough](../01-getting-started/08-ai-editor.md) if you want that
integration, or the [MCP reference](26-ai-reference.md#mcp-server) for its options.
