# Query Execution Plans

Generated: 2026-03-22 19:01:11

> Plans produced with `EXPLAIN` (no data modified). Placeholder UUID: `00000000-0000-4000-8000-000000000000`.

## accounts — lookup by primary key

```sql
SELECT * FROM accounts WHERE id = $1
```

```
Seq Scan on accounts  (cost=0.00..2.65 rows=1 width=120)
  Filter: ((id)::text = '00000000-0000-4000-8000-000000000000'::text)
```

## accounts — list by user (with ordering)

```sql
SELECT * FROM accounts WHERE user_id = $1 ORDER BY created_at ASC
```

```
Sort  (cost=2.66..2.66 rows=1 width=120)
  Sort Key: created_at
  ->  Seq Scan on accounts  (cost=0.00..2.65 rows=1 width=120)
        Filter: ((user_id)::text = '00000000-0000-4000-8000-000000000000'::text)
```

## accounts — exists for user + currency

```sql
SELECT COUNT(*) FROM accounts WHERE user_id = $1 AND currency = $2 AND status != $3
```

```
Aggregate  (cost=2.91..2.92 rows=1 width=8)
  ->  Seq Scan on accounts  (cost=0.00..2.91 rows=1 width=0)
        Filter: (((status)::text <> 'closed'::text) AND ((user_id)::text = '00000000-0000-4000-8000-000000000000'::text) AND ((currency)::text = 'USD'::text))
```

## users — lookup by primary key

```sql
SELECT * FROM users WHERE id = $1
```

```
Index Scan using users_pkey on users  (cost=0.14..8.16 rows=1 width=1820)
  Index Cond: ((id)::text = '00000000-0000-4000-8000-000000000000'::text)
```

## users — lookup by email (login / uniqueness check)

```sql
SELECT * FROM users WHERE email = $1
```

```
Index Scan using idx_users_email on users  (cost=0.14..8.16 rows=1 width=1820)
  Index Cond: ((email)::text = 'user@example.com'::text)
```

## transfers — lookup by primary key

```sql
SELECT * FROM transfers WHERE id = $1
```

```
Index Scan using transfers_pkey on transfers  (cost=0.41..8.43 rows=1 width=807)
  Index Cond: ((id)::text = '00000000-0000-4000-8000-000000000000'::text)
```

## transfers — lookup by idempotency key

```sql
SELECT * FROM transfers WHERE idempotency_key = $1
```

```
Index Scan using transfers_idempotency_key_unique on transfers  (cost=0.41..8.43 rows=1 width=807)
  Index Cond: ((idempotency_key)::text = 'idem-key-example'::text)
```

## transfers — paginated list for account (cursor-based)

```sql
SELECT * FROM transfers WHERE (from_account_id = $1 OR to_account_id = $2) AND created_at < $3 ORDER BY created_at DESC LIMIT $4
```

```
Limit  (cost=12.62..12.62 rows=1 width=807)
  ->  Sort  (cost=12.62..12.62 rows=1 width=807)
        Sort Key: created_at DESC
        ->  Bitmap Heap Scan on transfers  (cost=8.59..12.61 rows=1 width=807)
              Recheck Cond: ((((from_account_id)::text = '00000000-0000-4000-8000-000000000000'::text) AND (created_at < '2026-03-22 19:01:11'::timestamp without time zone)) OR (((to_account_id)::text = '00000000-0000-4000-8000-000000000000'::text) AND (created_at < '2026-03-22 19:01:11'::timestamp without time zone)))
              ->  BitmapOr  (cost=8.59..8.59 rows=1 width=0)
                    ->  Bitmap Index Scan on idx_transfers_from_account  (cost=0.00..4.29 rows=1 width=0)
                          Index Cond: (((from_account_id)::text = '00000000-0000-4000-8000-000000000000'::text) AND (created_at < '2026-03-22 19:01:11'::timestamp without time zone))
                    ->  Bitmap Index Scan on idx_transfers_to_account  (cost=0.00..4.29 rows=1 width=0)
                          Index Cond: (((to_account_id)::text = '00000000-0000-4000-8000-000000000000'::text) AND (created_at < '2026-03-22 19:01:11'::timestamp without time zone))
```

## transfers — duplicate detection within time window

```sql
SELECT COUNT(*) FROM transfers WHERE from_account_id = $1 AND to_account_id = $2 AND amount = $3 AND currency = $4 AND status NOT IN ($5, $6) AND created_at > NOW() - INTERVAL '60 seconds'
```

```
Aggregate  (cost=6.07..6.08 rows=1 width=8)
  ->  Index Scan using idx_transfers_to_account on transfers  (cost=0.29..6.07 rows=1 width=0)
        Index Cond: (((to_account_id)::text = '00000000-0000-4000-8000-000000000000'::text) AND (created_at > (now() - '00:01:00'::interval)))
        Filter: (((status)::text <> ALL ('{failed,completed}'::text[])) AND ((from_account_id)::text = '00000000-0000-4000-8000-000000000000'::text) AND (amount = 100.00000000) AND ((currency)::text = 'USD'::text))
```
