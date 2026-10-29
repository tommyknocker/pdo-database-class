# AI configuration and integration reference

Use this page when you need a specific provider setting, a custom endpoint, CLI
command, or MCP policy. For your first query and a working example, start with
[Understand and improve a query with AI](23-ai-analysis.md).

## Configuration

Configure **one provider**. Keys for other providers are not required. PDOdb uses
existing defaults for generation settings unless you override them.

### Environment Variables

`PDODB_AI_PROVIDER` selects the provider. Its credentials are:

| Provider value | Credential variable | Additional required configuration |
| --- | --- | --- |
| `openrouter` | `PDODB_AI_OPENROUTER_KEY` | Choose a model available to your account |
| `openai-compatible` | `PDODB_AI_OPENAI_COMPATIBLE_KEY` | `PDODB_AI_OPENAI_COMPATIBLE_BASE_URL` and `PDODB_AI_OPENAI_COMPATIBLE_MODEL` |
| `openai` | `PDODB_AI_OPENAI_KEY` | None for the default endpoint/model |
| `anthropic` | `PDODB_AI_ANTHROPIC_KEY` | None for the provider defaults |
| `google` | `PDODB_AI_GOOGLE_KEY` | None for the provider defaults |
| `microsoft` | `PDODB_AI_MICROSOFT_KEY` | `endpoint` and `deployment` in the provider configuration array |
| `deepseek` | `PDODB_AI_DEEPSEEK_KEY` | None for the provider defaults |
| `yandex` | `PDODB_AI_YANDEX_KEY` | `PDODB_AI_YANDEX_FOLDER_ID` |
| `ollama` | No key | A running Ollama instance with the selected generation model installed |

For the selected provider, replace `<PROVIDER>` with its uppercase name, using
underscores for hyphens: `OPENROUTER`, `OPENAI_COMPATIBLE`, etc.

| Optional variable | Purpose |
| --- | --- |
| `PDODB_AI_<PROVIDER>_MODEL` | Select a model available at that provider |
| `PDODB_AI_<PROVIDER>_TEMPERATURE` | Override sampling temperature if supported |
| `PDODB_AI_<PROVIDER>_MAX_TOKENS` | Override the output budget |
| `PDODB_AI_<PROVIDER>_TIMEOUT` | AI HTTP request timeout, seconds |
| `PDODB_AI_OLLAMA_URL` | Override the default local Ollama address |

These are independent overrides, not a list to copy into every application.
Model availability and support for optional parameters depend on your provider.

### Configuration Array

For applications that keep PDOdb settings in PHP, add one `ai` block to the
existing database configuration. This example configures only OpenRouter:

```php
'ai' => [
    'provider' => 'openrouter',
    'openrouter_key' => getenv('PDODB_AI_OPENROUTER_KEY'),
    'providers' => [
        'openrouter' => ['model' => 'your-model-id'],
    ],
],
```

Provider credentials use the keys `openai_key`, `anthropic_key`, `google_key`,
`microsoft_key`, `deepseek_key`, `yandex_key`, `openrouter_key`, or
`openai_compatible_key`. Configure only the one you use. `ollama_url` sets the local-server address; use `PDODB_AI_OLLAMA_URL` for
the environment equivalent.
For Yandex, `yandex_folder_id` or `providers.yandex.folder_id` sets the folder.

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
PDODB_AI_OPENAI_COMPATIBLE_BASE_URL=https://gateway.example.com/v1
PDODB_AI_OPENAI_COMPATIBLE_KEY=your-gateway-key
PDODB_AI_OPENAI_COMPATIBLE_MODEL=your-model-id
```

PDOdb appends `/chat/completions` to the base URL. The existing `openai` profile
also accepts `PDODB_AI_OPENAI_BASE_URL`. URLs must be HTTP(S), without embedded
credentials, a query string or a fragment. Requests do not follow redirects.

<details>
<summary>Custom headers and endpoint-specific request parameters</summary>

Configure these only if your endpoint requires them:

```php
'ai' => [
    'provider' => 'openrouter',
    'openrouter_key' => getenv('PDODB_AI_OPENROUTER_KEY'),
    'providers' => [
        'openrouter' => [
            'model' => 'your-model-id',
            'headers' => ['HTTP-Referer' => 'https://your-project.example'],
            'request_options' => [
                'temperature' => null,
                'max_tokens' => null,
                'max_completion_tokens' => 2000,
            ],
        ],
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
permissions. Selecting an explicit MCP alias never falls back to ambient
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
`.env.ai.local` is loaded only by those explicit live scripts and the showcase's
`--ai` mode. It is not automatically loaded by `explainAiAdvice()` in an application.

## See Also

- [First query and setup](23-ai-analysis.md)
- [Query advice API, context and privacy](24-ai-query-advice.md)
- [Three measured examples](25-ai-query-advice-in-practice.md)
- [Optional editor integration](../01-getting-started/08-ai-editor.md)
