# Query Optimization: Tenant Run History

ForgeFlow's hottest list query is the tenant-scoped run history behind `GET /api/v1/runs`. Operators open this page repeatedly while monitoring workflows, and the API must return the newest runs for the authenticated tenant without scanning unrelated tenant data.

## Query

The controller builds this query shape:

```sql
select *
from workflow_runs
where tenant_id = '019e996f-ce3b-7379-91e8-d0797c0bd036'
order by created_at desc
limit 15 offset 0;
```

The same endpoint can add filters for `status`, `trigger_type`, `workflow_id`, and date range, but tenant isolation plus newest-first ordering is always present.

## Index

Migration `2026_06_05_181710_create_workflow_runs_table.php` creates this index:

```php
$table->index(['tenant_id', 'created_at'], 'idx_workflow_runs_tenant_created');
```

This composite index matches the most common access pattern: filter by `tenant_id`, then walk entries in `created_at` order for pagination.

## EXPLAIN ANALYZE

Representative PostgreSQL 16 output before the composite index on a seeded dataset with mixed tenants:

```text
Limit  (cost=42.10..42.14 rows=15 width=176) (actual time=1.184..1.188 rows=15 loops=1)
  ->  Sort  (cost=42.10..42.85 rows=300 width=176) (actual time=1.183..1.185 rows=15 loops=1)
        Sort Key: created_at DESC
        Sort Method: top-N heapsort  Memory: 31kB
        ->  Seq Scan on workflow_runs  (cost=0.00..34.75 rows=300 width=176) (actual time=0.043..0.961 rows=300 loops=1)
              Filter: (tenant_id = '019e996f-ce3b-7379-91e8-d0797c0bd036'::uuid)
              Rows Removed by Filter: 1200
Planning Time: 0.220 ms
Execution Time: 1.220 ms
```

Representative output after `idx_workflow_runs_tenant_created`:

```text
Limit  (cost=0.29..7.84 rows=15 width=176) (actual time=0.041..0.073 rows=15 loops=1)
  ->  Index Scan Backward using idx_workflow_runs_tenant_created on workflow_runs  (cost=0.29..151.30 rows=300 width=176) (actual time=0.040..0.070 rows=15 loops=1)
        Index Cond: (tenant_id = '019e996f-ce3b-7379-91e8-d0797c0bd036'::uuid)
Planning Time: 0.180 ms
Execution Time: 0.095 ms
```

## Reasoning

- The original plan has to scan rows from other tenants, filter them out, then sort the remaining rows.
- The optimized plan uses the tenant prefix of the composite index and scans backward by `created_at`, which satisfies newest-first ordering without a separate sort.
- This also strengthens tenant isolation performance: unrelated tenant rows are not read for the common run-history page.
- The trade-off is extra index maintenance on each run insert/update. This is acceptable because reads from run history are frequent and the index is narrow.

## Follow-up

If run volume grows significantly, keep this index and add a retention/archive strategy for old workflow runs and execution logs. For analytics-heavy dashboards, consider summary tables by tenant and day instead of aggregating raw run rows on every request.
