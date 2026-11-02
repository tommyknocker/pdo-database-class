# Security Policy

## Supported versions

| Version | Supported |
|---|---|
| 3.x | Yes: security and bug fixes |
| 2.x | No. Upgrade to 3.x ([UPGRADE.md](UPGRADE.md)) |
| 1.x | No |

A security fix is released as a patch version and described in the [CHANGELOG](CHANGELOG.md).

## Reporting a vulnerability

Please do **not** report a vulnerability in a public issue, discussion or pull request.

Send an email to <vasiliy@krivoplyas.com> with the subject `SECURITY: PdoDb vulnerability report`, and include:

- the affected version (`vendor/bin/pdodb --version`, or the Composer version) and database;
- what you found and why it is a vulnerability;
- steps or a minimal script that reproduce it.

Do not send real credentials, tokens or production data.

## What counts

In scope: a way to make the library produce SQL that its documented API should not allow (injection through a value,
identifier, JSON path, operator or pattern that the documentation says is handled), leaking credentials or bound values
that the library promises to hide (profiler and slow-query logs, AI transport errors, CLI output), and the MCP server
exceeding its configured policy.

Not in scope: injection through SQL you pass on purpose as `Db::raw()`, a raw query or an identifier you build from user
input without the checks described in [Security](documentation/08-best-practices/01-security.md); running the CLI or the
MCP server with a database account that has more privileges than the task needs; weaknesses of the database server
itself.

## Using the library safely

[Security best practices](documentation/08-best-practices/01-security.md) covers parameter binding, raw queries, column
and table names, credentials, file loading and mass assignment. Two defaults to keep in mind: `LOAD DATA LOCAL INFILE` is
off unless you enable it in the PDO options, and `QueryExecutedEvent::getParams()` returns raw bound values, so filter
them before logging.
