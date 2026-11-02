# AI configuration and integration reference

This page is provider setup, CLI, and MCP. First result: [walkthrough](23-ai-analysis.md). Context and limits: [API guide](24-ai-query-advice.md). Measured cases: [three examples](25-ai-query-advice-in-practice.md).

## Configuration

Configure **one provider**. Keys for other providers are not required. PDOdb uses
existing defaults for generation settings unless you override them.

### Environment variables

Set the same variables for any provider:

```dotenv
PDODB_AI_PROVIDER=openrouter
PDODB_AI_API_KEY=your-private-key
PDODB_AI_MODEL=your-provider/model-id
```

`PDODB_AI_MODEL` is optional when the provider's default suits you. For an
OpenAI-compatible gateway, also set `PDODB_AI_BASE_URL` to its API prefix.
Ollama needs no API key; `PDODB_AI_BASE_URL` optionally selects its server.
Azure requires `PDODB_AI_BASE_URL` (resource endpoint) and `PDODB_AI_MODEL`
(the deployment name); Yandex requires
`PDODB_AI_FOLDER_ID`. Other optional overrides are `PDODB_AI_TEMPERATURE`,
`PDODB_AI_MAX_TOKENS`, `PDODB_AI_TIMEOUT` (seconds) and `PDODB_AI_RETRIES`
(see [Retries](#retries-and-transport)). Choose only the
settings your application needs. For example, to request a lower temperature:

```dotenv
PDODB_AI_TEMPERATURE=0.3
```

A `.env` file is loaded at connection setup, not by an AI method:

- `PdoDb::fromEnv()` loads `.env` from the current working directory (or the
  file selected by `PDODB_ENV_PATH`); `PdoDb::fromEnv($path)` loads the given
  file. Include database and AI variables in that file.
- `new PdoDb(...)` does not load `.env`. Load it in your application or set
  the process environment first. AI settings must be visible to PHP `getenv()`;
  a loader that populates only `$_ENV` is insufficient.
- PDOdb CLI commands load `.env` themselves, including when using a PHP database
  config file. The demo's `--ai` mode and live-test scripts also load `.env`;
  pass `--env=/path/to/file` to those scripts to select another file.

Values already present in the process environment are not overwritten by
`.env`. Keep files containing API keys out of version control.

Supported provider names: `openrouter`, `openai-compatible`, `openai`,
`anthropic`, `google`, `microsoft`, `deepseek`, `yandex`, and `ollama`.
Model access and supported request parameters depend on your account.

Temperature is optional; omit it to use the model profile's default. An explicit
`temperature` in PHP, `PDODB_AI_TEMPERATURE`, or per-call options must be a number
from 0 to 2. OpenAI GPT-5.2 and GPT-5.4 (excluding Pro variants) require `reasoning_effort=none` when
using temperature; PDOdb sets that automatically. Other GPT-5/6 profiles,
Anthropic Claude 4.7+/5, and Google Gemini 3.6+ reject an explicit temperature
in PDOdb rather than silently ignoring it. Compatible gateways may have different
rules: check your model's documentation and use `request_options` where needed. `max_tokens` and
`timeout` must be positive integers.

### PHP configuration

Alternatively, place the same settings in your database configuration:

```php
'ai' => [
    'provider' => 'openrouter',
    'api_key' => getenv('PDODB_AI_API_KEY'),
    'model' => 'your-provider/model-id',
],
```

Use `base_url`, `folder_id`, `temperature`, `max_tokens`, `timeout`, or `retries` only
when needed. For example, add `'temperature' => 0.3` to the `ai` array above.
The key and settings belong to the selected provider; they are not sent to
another provider. To override the temperature for a single query instead:

```php
$advice = $query->explainAiAdvice(options: ['temperature' => 0.3]);
```

### Migrating existing AI settings

The former provider-specific variables and nested PHP provider settings have
been replaced. For example, change `PDODB_AI_OPENROUTER_KEY` to
`PDODB_AI_API_KEY` and `PDODB_AI_OPENROUTER_MODEL` to `PDODB_AI_MODEL`.
In PHP, move the selected provider's key, model and other settings into the
flat `ai` array shown above. Configure only the provider you intend to use.

### Priority

Environment settings override the configuration array, which overrides provider
defaults. Per-call options such as `model`, `max_tokens`, `temperature`, and
`timeout` override those generation settings for that analysis call.
Endpoint-specific `request_options` are merged into the outgoing compatible
request last; avoid conflicting limits there.

### OpenRouter and OpenAI-compatible endpoints

OpenRouter uses `https://openrouter.ai/api/v1` automatically. No base URL is
needed for its normal profile. Other compatible services need their API prefix:

```dotenv
PDODB_AI_PROVIDER=openai-compatible
PDODB_AI_BASE_URL=https://gateway.example.com/v1
PDODB_AI_API_KEY=your-gateway-key
PDODB_AI_MODEL=your-model-id
```

PDOdb appends `/chat/completions` to the base URL. The existing `openai` profile
also accepts `PDODB_AI_BASE_URL`. URLs must be HTTP(S), without embedded
credentials, a query string or a fragment. Requests do not follow redirects.

<details>
<summary>Custom headers and endpoint-specific request parameters</summary>

Configure these only if your endpoint requires them:

```php
'ai' => [
    'provider' => 'openrouter',
    'api_key' => getenv('PDODB_AI_API_KEY'),
    'model' => 'your-model-id',
    'headers' => ['HTTP-Referer' => 'https://your-project.example'],
    'request_options' => [
        'temperature' => null,
        'max_tokens' => null,
        'max_completion_tokens' => 2000,
    ],
],
```

A `null` request option omits that field. Additional headers cannot override
Authorization, Host, Content-Type or Content-Length. Request options cannot
replace model/messages or enable streaming. These adapters support text Chat
Completions; Responses API, streaming and a native tool-call loop are not
implemented. Model-specific options are the caller's responsibility.

</details>

## Code-Level Integration

For the normal QueryBuilder API, context preview, structured findings and status
handling, see the [query-advice API guide](24-ai-query-advice.md).

You can also call the service directly:

```php
use tommyknocker\pdodb\ai\AiAnalysisService;

$ai = new AiAnalysisService($db);
$report = $ai->analyzeQuery('SELECT * FROM orders WHERE status = ?', 'orders', options: ['params' => ['pending']]);
$schemaReport = $ai->analyzeSchema('orders');
```

`analyzeQuery()` does not collect a plan automatically. To supply one, use
`QueryInspectionService::inspect()` and pass its `analysis` as the service's
`explainAnalysis` argument. Prefer QueryBuilder's `explainAiAdvice()` for the
complete collection flow. Schema reports use their own collection path; query
context/privacy options described in the API guide apply to query advice.

### Token usage

Providers report how many tokens an answer used. The service keeps the last figure and a running total:

```php
$ai = new AiAnalysisService($db);
$ai->analyzeQuery('SELECT * FROM orders WHERE status = ?', 'orders', options: ['params' => ['pending']]);

$ai->getLastUsage();    // AiUsage: inputTokens, outputTokens, totalTokens of the last request
$ai->getTotalUsage();   // the sum over all requests made through this service
$ai->getLastUsage()?->toArray();   // ['input_tokens' => 812, 'output_tokens' => 143, 'total_tokens' => 955]
```

A figure is `null` when the provider did not report it (it is never invented as zero), and `getLastUsage()` is `null` when
the whole answer carried no usage. Providers: OpenAI-style APIs (OpenAI, OpenRouter, DeepSeek, Azure), Anthropic,
Google, Ollama and Yandex report it. Use it for budgets and cost logs; convert tokens to money with your provider's
price list, which the library does not hold.

### Retries and transport

A request that fails for a reason that is likely to pass is repeated with growing waits (about 0.5 s, 1 s, 2 s, ... up
to 8 s, with some random spread): a rate limit (HTTP 429), a server error or timeout (408, 5xx), or a connection that
could not be made. A rejected key (401), a bad request (400), an unknown model (404) or an unreadable answer is
thrown at once, because repeating it cannot help and costs money.

```php
'ai' => ['provider' => 'openai', 'api_key' => '...', 'retries' => 2],   // default 2; 0 sends once; at most 10
```

`PDODB_AI_RETRIES` sets the same. Each attempt has its own `timeout`, so the worst case is about
`(retries + 1) × timeout` plus the waits. The wait does not follow a `Retry-After` header.

To use another HTTP client, proxy or a recorded fake in tests, pass an `AiTransport` to `AiAnalysisService` or to a
provider. A transport you pass is used as given; wrap it in `RetryingAiTransport` to get the same repeats:

```php
$ai = new AiAnalysisService($db, transport: new RetryingAiTransport(new MyPsr18Transport(), retries: 3));
```

## CLI Commands

Use the existing CLI database configuration (`.env`, process environment, or
`config/db.php`); see [CLI setup](21-cli-tools.md).

```bash
vendor/bin/pdodb ai query "SELECT * FROM orders WHERE status = ?" --params='["pending"]' --table=orders
vendor/bin/pdodb ai schema --table=orders
vendor/bin/pdodb ai optimize --table=orders
```

`ai query`, `ai schema` and `ai optimize` call the configured AI provider.
`ai doctor`, `ai context` and `ai docs "asObject"` do not call a model.
`pdodb ai doctor` prints a JSON summary: provider, model, whether an API key is configured, whether an endpoint is configured, whether the database is connected, and that no AI request was made. The key and any response body are omitted. It exits 0.
`pdodb ai doctor --probe` sends one short provider request only when that provider is configured, and it may incur a charge. The response body is still omitted. `probe_status` is `ok` when the request completes, `failed` when the configured provider was called and the request failed, or `not_sent` when the provider is not configured and no network request was made. `provider_network_checked` is true only after a request was attempted. A `failed` or `not_sent` probe exits non-zero.
`--temperature` must be a number from 0 to 2. `--max-tokens` and `--timeout` must be positive integers. A non-numeric value is rejected.
The bundled executable is `php bin/pdodb` in the PDOdb repository itself.

## MCP Server

PDOdb includes an MCP (Model Context Protocol) server for integration with AI agents and IDEs that support MCP.

### Starting the MCP Server

Normally your editor starts the local PHP process using the launch configuration
printed by `pdodb mcp config`. No separate daemon or network port is needed.
See the [AI editor walkthrough](../01-getting-started/08-ai-editor.md) for a complete
example: connect the editor, inspect a real table, and write a PDOdb query.

The executable `vendor/bin/pdodb-mcp` is a stdio protocol endpoint. Starting it
manually waits for MCP messages on stdin, rather than opening a chat interface.
It uses the same connection configuration as the PDOdb CLI; see setup below.

### Agent tools without an additional LLM key

The server can provide facts to the model already used by your editor. These
tools do not call an AI provider:

| Tool | Input | Result |
| --- | --- | --- |
| `get_context` | `{}` | PDOdb/PHP/database versions, dialect capabilities, connection names and result contracts; no credentials |
| `search_docs` | `query`, optional `limit` (1–20) | Excerpts from the installed package documentation with source paths and section line numbers |
| `search_schema` | Optional `query`, `kind` (`tables`, `columns`, `schemas`), `schema`, `table`, `limit` (1–100), `offset` | Visible object names or matching columns, with `next_offset` |
| `get_schema` | Optional `table`, `schema` | Columns, indexes and visible foreign keys; omit table for all visible tables in that schema |
| `inspect_query` | `sql`, optional `params`, `table` | Estimated EXPLAIN evidence, parsed plan, issues, recommendations and warnings |

For example:

```json
{
  "name": "inspect_query",
  "arguments": {
    "sql": "SELECT * FROM users WHERE id = ?",
    "params": [1],
    "table": "users"
  }
}
```

Named parameters are accepted as an object. `inspect_query` accepts one
SELECT/WITH statement, conservatively rejects internal semicolons, never calls
EXPLAIN ANALYZE and does not echo parameter values. Plans may still contain SQL
literals; do not treat the output as fully anonymized. `measurements: false`
means the plan contains estimates rather than measured execution times.
MSSQL reports `source: unavailable` because this adapter cannot collect a real
plan. Oracle EXPLAIN uses PLAN_TABLE and requires appropriate permissions;
parameterized Oracle plans report `source: unavailable` because the adapter
cannot safely bind EXPLAIN parameters. Values are not interpolated by this service.

### Existing AI-report tools

`analyze_query`, `explain_plan` and `suggest_indexes` continue to use the configured
AI provider. They support all provider names, including `openrouter` and
`openai-compatible`. Query tools accept positional/named `params`.
`analyze_query` reports warnings when a plan could not be collected.
Provider identity in results reflects the actual configuration.

Tool failures have `isError: true`. Array results are also available as
`structuredContent`. Tool content is limited to 128 KiB; use `search_schema` and a
specific `get_schema` table instead of requesting an entire large schema.
Requests are limited to 1 MiB. The server negotiates the 2024-11-05 through
2025-11-25 protocol revisions; the stateless 2026 revision is not implemented.

### Setup and diagnostics

```bash
pdodb ai setup
pdodb ai doctor                  # Connection/configuration check, no LLM request
pdodb ai doctor --probe          # One short provider request; it may incur a charge
pdodb ai context                 # JSON environment and result contracts
pdodb ai docs "asObject"          # Local docs search, no database connection needed
pdodb mcp config                 # JSON launch preview, no files are written
pdodb mcp doctor                 # Validate the MCP connection, policy and catalog access
```

The MCP launcher and `pdodb mcp serve` use the same database configuration as the
CLI: environment/.env, or `config/db.php` / `PDODB_CONFIG_PATH`. Launch in the
project directory when generating the preview: it records that directory with
`--project` so the configuration resolves correctly when an editor starts the
server elsewhere. Generated launch paths are absolute and local to the machine. The preview uses the `mcpServers`
JSON format; translate it if your client uses a different configuration format.
Keep secrets in your environment or a local, untracked configuration file.

`doctor` checks the database and shows whether a provider key is configured;
it does not contact a model or make a paid request. Environment settings take
precedence over configuration arrays, followed by defaults. Agent tools require
no LLM key. The server does not expose a general SQL execution tool. Database
permissions and database-side statement limits remain important: client timeout
alone does not cancel work in the database.

The portable `skills/pdodb-query-builder/SKILL.md` bundled with PDOdb explains
result modes, scopes and evidence-based query work. Install its folder using
your agent's skill installation mechanism. It is optional and not installed
into your project automatically.

### MCP visibility, connections and limits

The optional project file `config/mcp.php` returns a policy array. Start from
[`config/mcp.example.php`](../../config/mcp.example.php):

```php
return [
    'connection' => 'diagnostics',
    'allowed_schemas' => ['public'],
    'allowed_tables' => ['users', 'orders'],
    'external_ai_enabled' => false,
    'statement_timeout_ms' => 5000, // PostgreSQL/MariaDB; use 0 for other adapters.
];
```

Define the `diagnostics` alias in `config/db.php` with a database account whose
permissions suit the intended use. The name itself does not grant read-only
permissions; see [Read-only database account](#read-only-database-account) for
examples. Selecting an explicit MCP alias never falls back to ambient
`PDODB_DRIVER` credentials or the application's default connection. Existing
launches without this option keep the original CLI connection selection.
In PHP, the optional third `McpServer` constructor argument accepts `McpConfig`;
selection uses a shallow copy of PdoDb so the caller's active alias is unchanged.
A supplied custom AI service must already target the desired connection; do not
combine it with automatic alias selection.

Environment overrides take precedence over this file:

| Variable | Format |
| --- | --- |
| `PDODB_MCP_CONFIG_PATH` | Explicit policy-file path; a missing file is an error |
| `PDODB_MCP_CONNECTION` | Named alias in `config/db.php` / `PDODB_CONFIG_PATH` |
| `PDODB_MCP_ALLOWED_TABLES` | JSON array, e.g. `["users","orders"]` |
| `PDODB_MCP_ALLOWED_SCHEMAS` | JSON array, e.g. `["public"]` |
| `PDODB_MCP_QUERY_ANALYSIS_ENABLED` | `true` / `false` or `1` / `0` |
| `PDODB_MCP_EXTERNAL_AI_ENABLED` | `true` / `false` or `1` / `0` |
| `PDODB_MCP_STATEMENT_TIMEOUT_MS` | Integer milliseconds, 0–300000 |

`null` allowlists in PHP preserve unrestricted visibility; `[]` explicitly
exposes no objects. Unknown options, malformed lists and nonboolean flags are
rejected. Identifier matching is exact and case-sensitive: use the names returned
by discovery, including Oracle's usual uppercase names. MCP currently accepts
simple identifiers (letters/digits/underscore/dollar sign), optionally qualified
as `schema.table`; quoted names with punctuation or spaces are not supported.

An unqualified allowed table applies only to the metadata default schema;
`public.users` applies only to that exact namespace. The metadata default is the
connection's current schema, or the first allowed schema if the current one is
excluded. `get_context` reports it. Schemas, tables, columns, `get_schema` and
`table://` resources use the same policy; references to hidden foreign-key
targets are omitted and `foreign_keys_filtered` reports that omission.

**Visibility restrictions force metadata-only mode:** only `get_context`,
`search_docs`, `search_schema` and `get_schema` are advertised. Raw SQL analysis
and the three AI-report tools cannot be re-enabled by the boolean flags while
an allowlist is present. A `table` argument cannot prove that arbitrary SQL
accesses only that table, so allowing EXPLAIN in this mode would bypass the view.
The policy controls this MCP server's output, not database permissions or other
clients' access; it does not analyze expressions inside allowed index definitions.
Without allowlists, the query/AI flags can independently disable their tools.

Column and schema discovery examples:

```json
{"name":"search_schema","arguments":{"kind":"columns","query":"email","schema":"public","table":"users"}}
{"name":"search_schema","arguments":{"kind":"schemas"}}
{"name":"get_schema","arguments":{"schema":"public","table":"users"}}
```

Column results identify the schema, table, column and type. To avoid unlimited
per-table scans, column search requires `table` when a schema has more than 200
visible tables. Metadata queries bind both schema and table, so equal table or
constraint names in different namespaces do not mix. `resources/list` returns
up to 100 entries and a `nextCursor`; `table://public.users` selects a qualified
resource. Resource contents share the 128 KiB size limit with tool content.

`statement_timeout_ms=0` leaves session settings untouched. PostgreSQL and
MariaDB support a scoped server-side per-statement limit in this adapter. The
original setting is restored in `finally`, including after a statement timeout
or other error, and a stricter existing limit is preserved. An active caller
transaction is rejected when a scoped timeout is requested. Other adapters
reject a nonzero limit instead of presenting a connection/lock timeout as query
cancellation; SQLite busy timeouts and MySQL's SELECT-only execution limit are
not substitutes for this feature. MariaDB cancellation is checked periodically,
so it is not a hard wall-clock deadline. These limits do not bound a whole MCP
request containing several statements or a call to an external AI provider.
`mcp doctor` reports support and tests catalog access without invoking an LLM.

### Read-only database account

The SQL that reaches `inspect_query` is written by an editor model, not by you.
The tool only checks that the text starts with `SELECT` or `WITH` and contains
no internal semicolon, and it runs `EXPLAIN` without `ANALYZE`. That check is a
convenience, not a security boundary: a `WITH` statement can contain a data
modifying clause, and planning may evaluate functions. The boundary that holds
is the database account. Point the MCP `connection` alias at an account that can
read but cannot write, and keep it separate from the application's account.

Replace the names and `<password>` placeholders. Grant only the schemas or tables
the assistant should see; the MCP allowlists limit what this server returns, not
what the account can read.

PostgreSQL:

```sql
CREATE ROLE pdodb_mcp LOGIN PASSWORD '<password>';
GRANT CONNECT ON DATABASE app TO pdodb_mcp;
GRANT USAGE ON SCHEMA public TO pdodb_mcp;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO pdodb_mcp;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO pdodb_mcp;
-- Defense in depth: new sessions start read-only (a session may still override it).
ALTER ROLE pdodb_mcp SET default_transaction_read_only = on;
```

MySQL and MariaDB:

```sql
CREATE USER 'pdodb_mcp'@'localhost' IDENTIFIED BY '<password>';
GRANT SELECT ON app.* TO 'pdodb_mcp'@'localhost';
```

SQL Server (`inspect_query` reports `unavailable` on this adapter, but schema
discovery works with the same account):

```sql
CREATE LOGIN pdodb_mcp WITH PASSWORD = '<password>';
USE app;
CREATE USER pdodb_mcp FOR LOGIN pdodb_mcp;
ALTER ROLE db_datareader ADD MEMBER pdodb_mcp;
```

Oracle:

```sql
CREATE USER pdodb_mcp IDENTIFIED BY "<password>";
GRANT CREATE SESSION TO pdodb_mcp;
GRANT SELECT ON app_owner.users TO pdodb_mcp;  -- one grant per table, or through a role
```

`EXPLAIN PLAN` writes its rows to `PLAN_TABLE`, so this account needs a usable
`PLAN_TABLE`. Current Oracle releases provide a session-private one; on older
releases run `utlxplan.sql` as `pdodb_mcp`. That is the only write the account
needs, and it never touches application tables.

SQLite has no accounts. Open a database file that the PHP process cannot write
to (file permissions), or give the assistant a copy of the database.

Check the result before enabling query inspection: connect with the new account
and confirm that `INSERT`, `UPDATE`, `DELETE` and DDL statements fail, then run
`pdodb mcp doctor` to confirm catalog access for the `diagnostics` alias.

### Resources and prompts

`table://users` returns that table's schema. The prompts `analyze_query` (required
`sql` argument) and `optimize_schema` (required `table` argument) return resolved
messages for the client. These metadata tools make no additional provider calls. Your agent client may
include their output in its own model context; AI-report tools additionally
send SQL/schema to the configured PDOdb AI provider.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| Provider unavailable | Selected provider, its own key, and endpoint configuration |
| Model not found | Exact model ID and availability in your account or local Ollama instance |
| Empty response/output budget exhausted | Output limit and the selected model's reasoning settings; do not retry automatically without reviewing cost |
| HTTP 401/403 | Provider credentials and permissions |
| HTTP 429 | Provider rate limit or account allowance |
| Plan unavailable | `planSource`, warnings and the adapter limitations in the API guide |
| Unexpected settings | Environment variables take precedence over configuration arrays |

For private repository smoke tests, use the [testing guide](../../tests/README.md#local-ai-tests-and-private-credentials).
`.env` is loaded by the explicit live scripts and the showcase's `--ai` mode.
A PHP application loads it via `PdoDb::fromEnv()` or its own environment loader.

## See Also

- [First query and setup](23-ai-analysis.md)
- [Query advice API, context and privacy](24-ai-query-advice.md)
- [Three measured examples](25-ai-query-advice-in-practice.md)
- [Optional editor integration](../01-getting-started/08-ai-editor.md)
