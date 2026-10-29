# Use PDOdb from an AI editor

The useful case: you are changing an existing PHP application, and your editor's
assistant needs the **actual database schema** and the **installed PDOdb API** to
write the right query. Instead of copying table definitions into chat, you let
the assistant retrieve those facts through PDOdb's optional MCP integration.

This walkthrough uses features in the current development branch, not the
published v2.13.0 package.

## What runs, and who starts it?

Your editor starts a local PHP process and talks to it over standard input and
output. You do not deploy a daemon, open a port, or add a server to your web
application. The process serves tool requests while the editor is connected;
it does not monitor or optimize the database in the background.

The editor supplies the AI model. Schema discovery, local documentation search,
and query inspection need no additional OpenRouter/OpenAI key in PDOdb. The
editor can send tool results, including schema information, to its own model.
The separate AI report tools use PDOdb's configured AI provider.

## Example: write a query using the real table

### 1. Configure access in your existing project

Start with a project whose PDOdb CLI already connects to a development database
through its local `.env` or `config/db.php`. Keep credentials out of Git.
For this walkthrough, create `config/mcp.php`:

```php
<?php
return [
    'allowed_tables' => ['users'],
    'query_analysis_enabled' => false,
    'external_ai_enabled' => false,
];
```

Replace `users` with an existing table name. This profile exposes metadata and
documentation tools; it does not expose query inspection or AI report tools.
It uses your existing default connection. To select a separate database account,
define a named connection in `config/db.php` and set `connection` in this file
to that alias; see [connection policies](../05-advanced-features/23-ai-analysis.md#setup-and-diagnostics).

### 2. Connect the editor

From your project directory, run:

```bash
vendor/bin/pdodb mcp doctor
vendor/bin/pdodb mcp config
```

When working in the PDOdb repository itself, use `php bin/pdodb` instead of
`vendor/bin/pdodb`.

The second command prints a JSON launch configuration containing absolute local
paths. For Cursor, put that JSON in your project's `.cursor/mcp.json`, add
`"type": "stdio"` to the `mcpServers.pdodb` object, then enable the server in the
editor's MCP settings. Other clients may require translating the generated
configuration to their format. See [Cursor's MCP documentation](https://cursor.com/docs/mcp).

The editor launches PHP itself. You do not need to run `pdodb-mcp` in another
terminal. Running it directly waits for protocol messages on stdin; it is not
an interactive chat prompt.

### 3. Ask for a concrete change

In the editor's agent chat:

> Use the PDOdb tools to inspect the users table and check the installed query
> builder API. Write a PHP query returning active users as objects. Use actual
> column names. Do not change the database.

The assistant can use `get_context`, `search_schema`, `get_schema`, and
`search_docs` to discover the database dialect, columns, and `asObject()` API.
Tool selection depends on your editor and model; check that the assistant
actually calls the tools before relying on its answer.

For example, **if the schema contains an `is_active` column**, the resulting
application code could be:

```php
$users = $db->find()
    ->from('users')
    ->where('is_active', 1)
    ->asObject()
    ->get();

foreach ($users as $user) {
    echo $user->name;
}
```

The benefit is that the assistant can check whether that column exists and how
this PDOdb version returns objects. MCP supplies facts; the editor proposes or
edits your PHP code according to your instructions. MCP does not apply database
migrations or execute this SELECT to return application rows.

## Another use case: investigate a slow query

With query inspection enabled, ask the assistant to explain a parameterized SQL
query and relate its estimated plan to existing indexes. `inspect_query`
provides database evidence without calling an additional model. It does not
measure production latency or create indexes automatically.

This requires a different profile: no table/schema visibility restrictions,
`query_analysis_enabled => true`, and preferably a database account with
read-only permissions. The metadata-only profile above deliberately disables
SQL tools. Adapter limitations and statement limits are described in the
[MCP reference](../05-advanced-features/23-ai-analysis.md#mcp-server).

## When to skip MCP

If your assistant already has the relevant DDL, PDOdb documentation, and a
terminal with database access, MCP may add little. It packages access for an
editor; it does not give the database a new capability.

For AI advice from your own PHP application, use the
[PHP AI APIs](../05-advanced-features/23-ai-analysis.md) directly. For your own
agent using native function calling, a standalone tool registry/dispatcher is
not currently provided. Neither use case requires running an MCP server.
