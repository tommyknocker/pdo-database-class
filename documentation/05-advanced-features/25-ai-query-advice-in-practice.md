# AI query advice in practice

A slow page, a JOIN that scans too much, and a query whose index already fits.
Here are three complete examples of `explainAiAdvice()` working with real
SQLite plans and reproducible data, including actual OpenRouter answers.

**The useful part:** PDOdb supplies the model with the query's execution plan,
columns, existing indexes and JOIN schemas automatically. You can spend your
time checking the proposed change instead of assembling a database prompt.

```mermaid
flowchart LR
    Q[Your PDOdb query] --> C[Execution plan, schemas, indexes]
    C --> A[One AI request]
    A --> V[Review and verify the change]
```

| Use case | What changed in the plan | Observed local SELECT time |
| --- | --- | --- |
| [First page of pending orders](#1-first-page-of-pending-orders) | Scan + temporary sort → index lookup in the requested order | 4.6860 → 0.0200 ms |
| [Orders from a small customer group](#2-orders-from-a-small-customer-group) | Scan orders → look up customers and orders through indexes | 16.7845 → 0.2249 ms |
| [Recent orders for one customer](#3-recent-orders-for-one-customer) | The existing index already handles the filter and order | No new index |

These are measurements of this synthetic SQLite fixture, **not production
benchmarks or promised speedups**. AI request time is excluded. The source,
plans, complete selected answers and result checksums are available below.

## Try it locally

Use a PDOdb 2.14.0 or newer repository checkout, PHP 8.4+ and `pdo_sqlite`.
From the repository root:

```bash
composer install
php examples/07-performance/05-ai-query-advice.php
```

That is enough to create **100,000 orders and 2,000 customers**, show the plans,
apply the example's reviewed index changes and measure the SELECTs. The default
run makes no AI requests and needs no database container or API key.

To get your own live answers, put your provider settings in the environment or
copy [.env.ai.local.example](../../.env.ai.local.example) to `.env.ai.local` and
fill in the key and a model ID available to your account. For this walkthrough,
set `PDODB_AI_LIVE_MAX_TOKENS=512`. Keep the file private and untracked. Then run:

```bash
php examples/07-performance/05-ai-query-advice.php --ai
```

This explicitly makes three requests, each capped at **512 output tokens**.
The example reuses `PDODB_AI_LIVE_MAX_TOKENS`, `PDODB_AI_LIVE_TIMEOUT`, and the
optional OpenRouter reasoning-effort setting from the private file. For a
reasoning model, choose a setting that leaves enough room for visible output.
A different private file can be selected with `--env=/path/to/private.env`.

The script always uses a fresh SQLite database **in memory**, even when your
application environment selects another database. Its deterministic fixture and
reviewed DDL are in the [example source](../../examples/07-performance/support/QueryAdviceShowcase.php).
No model-generated SQL is executed. The benchmark deliberately runs SELECTs to
measure them; `explainAiAdvice()` itself collects estimated plans and metadata.

## 1. First page of pending orders

Your endpoint needs the 50 newest pending orders. The fixture has 5,000 pending
orders among 100,000 orders, with no secondary indexes at the start.

```php
$query = $db->find()
    ->from('orders')
    ->select(['id', 'user_id', 'created_at'])
    ->where('status', 'pending')
    ->orderBy('created_at', 'DESC')
    ->limit(50);

$advice = $query->explainAiAdvice(options: [
    'context' => ['goal' => 'Investigate the first page of pending orders'],
]);

echo $advice->aiAnalysis;
```

The collected plan:

```text
SCAN orders
USE TEMP B-TREE FOR ORDER BY
```

**What the model noticed — excerpt from the captured answer:**

> Candidate: an index on `(status, created_at DESC)` (or `(status, created_at)`)
> could let the engine seek to the `status` value and read `created_at` in order,
> avoiding the temp B-tree for page one.

It also calls out storage and write overhead and asks for plan verification.
We tested this candidate explicitly in the fixture:

```sql
CREATE INDEX idx_orders_status_created ON orders(status, created_at DESC);
```

The new plan:

```text
SEARCH orders USING INDEX idx_orders_status_created (status=?)
```

**Verified result:** the temporary sort disappeared; the same 50 rows were
returned in the same order. The median SELECT time changed from
**4.6860 ms to 0.0200 ms** on this fixture.

<details>
<summary>Read the full captured AI answer</summary>

**Plan:** SQLite reports `SCAN orders` (full table scan) then `USE TEMP B - TREE FOR ORDER BY` — meaning every matching row is found by scanning, then sorted in a temp B-tree, before LIMIT takes page one.

**Findings (prioritized)**

1. *Scan + temp sort for first page.* Evidence: plan nodes above; catalog shows `indexes: []`. Candidate: an index on `(status, created_at DESC)` (or `(status, created_at)`) could let the engine seek to the `status` value and read `created_at` in order, avoiding the temp B-tree for page one. Tradeoffs: extra storage and slower INSERT/UPDATE; `DESC` handling varies by SQLite version. Verify with `EXPLAIN QUERY PLAN` using a bound value and a real plan.

**Missing evidence:** no row counts, no actual execution time, `estimated_rows` null, parameter value unknown. Confirm index usefulness on realistic data before adopting.

</details>

## 2. Orders from a small customer group

A report needs orders from customers in a particular country. In this fixture,
20 of 2,000 customers are in that group. An index for the previous page already
exists, but its leading `status` column does not help this query.

```php
$query = $db->find()
    ->from('users u')
    ->join('orders o', 'o.user_id = u.id')
    ->select(['o.id', 'o.total_cents'])
    ->where('u.country', 'IS')
    ->where('o.total_cents', 9000, '>=')
    ->orderBy('o.id');

$advice = $query->explainAiAdvice(options: [
    'context' => [
        'goal' => 'Investigate this JOIN; the selected group is 1% of this synthetic customer fixture',
    ],
]);
```

The starting plan:

```text
SCAN o
SEARCH u USING INTEGER PRIMARY KEY (rowid=?)
```

SQLite scans the orders and looks up each customer by primary key. PDOdb gives
the model **both schemas**, their foreign key, and the existing status index.
The model identifies that this index serves a different query and proposes
indexing the country filter and the order-side join/filter.

**Captured recommendation — excerpts:**

> candidate `idx_users_country (country)`

> consider `idx_orders_user_id_id (user_id, id)` or
> `idx_orders_user_id_total (user_id, total_cents)`

We selected and tested the country index plus the join/filter candidate:

```sql
CREATE INDEX idx_users_country ON users(country);
CREATE INDEX idx_orders_user_total ON orders(user_id, total_cents);
```

The resulting plan:

```text
SEARCH u USING COVERING INDEX idx_users_country (country=?)
SEARCH o USING COVERING INDEX idx_orders_user_total (user_id=? AND total_cents>?)
USE TEMP B-TREE FOR ORDER BY
```

**Verified result:** both table accesses use covering indexes. The final sort
remains, so this is not presented as eliminating all work. The query returned
the same **837 rows in the same order**. The median SELECT time changed
from **16.7845 ms to 0.2249 ms**.

This is where schema context matters: the model can distinguish an unrelated
existing index from one that supports the actual JOIN.

<details>
<summary>Read the full captured AI answer</summary>

**Plan**: SQLite scans `orders` (`SCAN o`), then looks up `users` by primary key (`SEARCH u USING INTEGER PRIMARY KEY`). The `country` filter isn't pushed to `users` first; no index is used on `orders`.

**Findings (prioritized)**

1. **No index for country filter**
   - Observation: `users` has only a PK; no index on `country`.
   - Evidence: schema shows `users.indexes: []`; plan chooses scan of `o` and PK lookup of `u`.
   - Change: candidate `idx_users_country (country)`. This is a heuristic, not measured.
   - Tradeoffs: extra write/storage; low benefit if `country` is not selective, but context states "IS" ≈1%.
   - Verify: run `EXPLAIN QUERY PLAN` with bound parameter; check index usage and row estimate.

2. **`orders` full scan / ordering**
   - Observation: `SCAN o` plus `ORDER BY o.id`; existing index `idx_orders_status_created` doesn't cover `user_id` or `total_cents`.
   - Evidence: plan detail `SCAN o`; `used_index: null`; index columns are `status, created_at DESC`.
   - Change: consider `idx_orders_user_id_id (user_id, id)` or `idx_orders_user_id_total (user_id, total_cents)`, but only if the join path actually filters/orders on those columns after a `users`-driven plan.
   - Tradeoffs: additional index maintenance; SQLite may still prefer scanning `orders` if `users` is large.
   - Verify: `EXPLAIN QUERY PLAN` after candidate index in a test DB; compare `SEARCH`/`SCAN` and row estimates.

**Missing evidence/limitations**: no row counts, cardinality, or actual timing; parameters redacted; equal placeholders don't prove selectivity. Index changes require developer review and are not applied.

</details>

## 3. Recent orders for one customer

The fixture now prepares an application state with an existing index on
`orders(user_id, created_at DESC)`. You want to review this query before adding
another index:

```php
$advice = $db->find()
    ->from('orders')
    ->select(['id', 'created_at', 'total_cents'])
    ->where('user_id', 42)
    ->orderBy('created_at', 'DESC')
    ->limit(20)
    ->explainAiAdvice(options: [
        'context' => ['goal' => 'Review existing indexes. Is another index necessary?'],
    ]);
```

The actual plan:

```text
SEARCH orders USING INDEX idx_orders_user_created (user_id=?)
```

**Captured answer — excerpt:**

> No new index needed. Evidence: plan
> `SEARCH orders USING INDEX idx_orders_user_created (user_id = ?)`;
> index columns `(user_id ASC, created_at DESC)`.

The model discusses a possible covering index but weighs the extra write and
storage cost. The existing index already serves the equality filter and order;
there is no temporary sort in the captured plan. **We applied no optimization
change for this case.**

A useful review can justify keeping the current design, with evidence from the
existing schema and plan.

<details>
<summary>Read the full captured AI answer</summary>

**Plan:** SQLite uses `idx_orders_user_created` to seek `user_id = ?`; the index's second key is `created_at DESC`, matching `ORDER BY "created_at" DESC`, so ordering likely comes free from that index. It covers `id` (rowid in SQLite) and `created_at`; `total_cents` isn't in it, requiring a table lookup per row.

**Findings (prioritized)**
1. No new index needed. Evidence: plan `SEARCH orders USING INDEX idx_orders_user_created (user_id = ?)`; index columns `(user_id ASC, created_at DESC)`. A `(user_id, created_at DESC, total_cents)` covering index could avoid table lookups, but adds write/storage cost and duplicates an existing index. Verify with `EXPLAIN QUERY PLAN` and actual timing on real data—estimated cost isn't elapsed time.

**Missing evidence/limitations:** No row counts, cardinality, or measured latency; selectivity of `:user_id_1` unknown. Test with `EXPLAIN QUERY PLAN` and representative data before any index change.

</details>

## Use it with your own query

Keep your existing `PdoDb` connection and query. Configure your
[AI provider](23-ai-analysis.md#configuration), then:

```php
// Preview the database context locally, without an AI request.
$context = $query->explainAiContext();

// Ask for advice with your workload constraints.
$advice = $query->explainAiAdvice(options: [
    'context' => [
        'goal' => 'Reduce latency for this endpoint',
        'constraints' => ['Preserve result ordering', 'The table is write-heavy'],
        'language' => 'English',
    ],
]);

if ($advice->status === 'ok') {
    echo $advice->aiAnalysis;
}
```

PDOdb makes one model request for an advice call. The example command has three
calls because it demonstrates three queries. A separate MCP server or agent loop
is not required.

For a report or administrative UI, optional structured findings include evidence
pointers into the supplied context. The [API guide](24-ai-query-advice.md) covers
that format, status handling, privacy settings, context budgets and adapter
limitations. Review its disclosure controls before using a real application DB.

## How these results were recorded

- **Data:** deterministic synthetic orders and customers; no production data.
- **Database:** SQLite 3.45.1 in memory; PHP 8.5.12.
- **Model:** `deepseek/deepseek-v4.1-flash` through OpenRouter, at most 512 output tokens per answer.
- **Captures:** representative cases selected from two live runs on 2026-10-29.
  The saved responses are unedited; the excerpts above are shortened selections.
- **Timing:** two warmups, then nine SELECT executions with all result rows
  fetched; the table shows the median. Setup, metadata collection and AI latency
  are excluded. These are a local demonstration, not a general database benchmark.
- **Correctness:** before/after result arrays have matching SHA-256 checksums,
  including row order. Indexes are applied by fixed, reviewed example code.

Inspect the [captured plans, measurements and full selected answers](assets/ai-query-advice-sqlite.json),
or generate a fresh report:

```bash
php examples/07-performance/05-ai-query-advice.php --json
php examples/07-performance/05-ai-query-advice.php --ai --output=/tmp/query-advice.json
```

Model wording and timings will vary. The reproducible part is the fixture,
queries, candidate index changes and result verification. A model recommendation
becomes useful when its evidence and its effect can be checked.
