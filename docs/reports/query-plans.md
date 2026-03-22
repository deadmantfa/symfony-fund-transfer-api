# Query Execution Plans

Generated: 2026-03-21 22:22:44

> Plans produced with `EXPLAIN` (no data modified). Placeholder UUID: `00000000-0000-4000-8000-000000000000`.

## accounts — lookup by primary key

```sql
SELECT * FROM accounts WHERE id = $1
```

```
Seq Scan on accounts  (cost=0.00..4.69 rows=1 width=120)
  Filter: ((id)::text = '00000000-0000-4000-8000-000000000000'::text)
```

## accounts — list by user (with ordering)

```sql
SELECT * FROM accounts WHERE user_id = $1 ORDER BY created_at ASC
```

```
Sort  (cost=4.70..4.70 rows=1 width=120)
  Sort Key: created_at
  ->  Seq Scan on accounts  (cost=0.00..4.69 rows=1 width=120)
        Filter: ((user_id)::text = '00000000-0000-4000-8000-000000000000'::text)
```

## accounts — exists for user + currency

```sql
SELECT COUNT(*) FROM accounts WHERE user_id = $1 AND currency = $2 AND status != $3
```

```
Aggregate  (cost=5.37..5.38 rows=1 width=8)
  ->  Seq Scan on accounts  (cost=0.00..5.36 rows=1 width=0)
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
Index Scan using transfers_pkey on transfers  (cost=0.27..8.29 rows=1 width=792)
  Index Cond: ((id)::text = '00000000-0000-4000-8000-000000000000'::text)
```

## transfers — lookup by idempotency key

```sql
SELECT * FROM transfers WHERE idempotency_key = $1
```

```
Index Scan using transfers_idempotency_key_unique on transfers  (cost=0.27..8.29 rows=1 width=792)
  Index Cond: ((idempotency_key)::text = 'idem-key-example'::text)
```

## transfers — paginated list for account (cursor-based)

```sql
SELECT * FROM transfers WHERE (from_account_id = $1 OR to_account_id = $2) AND created_at < $3 ORDER BY created_at DESC LIMIT $4
```

```
Limit  (cost=14.49..14.49 rows=2 width=792)
  ->  Sort  (cost=14.49..14.49 rows=2 width=792)
        Sort Key: created_at DESC
        ->  Bitmap Heap Scan on transfers  (cost=8.57..14.48 rows=2 width=792)
              Recheck Cond: ((((from_account_id)::text = '00000000-0000-4000-8000-000000000000'::text) AND (created_at < '2026-03-21 22:22:44'::timestamp without time zone)) OR (((to_account_id)::text = '00000000-0000-4000-8000-000000000000'::text) AND (created_at < '2026-03-21 22:22:44'::timestamp without time zone)))
              ->  BitmapOr  (cost=8.57..8.57 rows=2 width=0)
                    ->  Bitmap Index Scan on idx_transfers_from_account  (cost=0.00..4.28 rows=1 width=0)
                          Index Cond: (((from_account_id)::text = '00000000-0000-4000-8000-000000000000'::text) AND (created_at < '2026-03-21 22:22:44'::timestamp without time zone))
                    ->  Bitmap Index Scan on idx_transfers_to_account  (cost=0.00..4.28 rows=1 width=0)
                          Index Cond: (((to_account_id)::text = '00000000-0000-4000-8000-000000000000'::text) AND (created_at < '2026-03-21 22:22:44'::timestamp without time zone))
```

## transfers — duplicate detection within time window

```sql
SELECT COUNT(*) FROM transfers WHERE from_account_id = $1 AND to_account_id = $2 AND amount = $3 AND currency = $4 AND status NOT IN ($5, $6) AND created_at > NOW() - INTERVAL '60 seconds'
```

```
Aggregate  (cost=8.31..8.32 rows=1 width=8)
  ->  Index Scan using idx_transfers_to_account on transfers  (cost=0.28..8.31 rows=1 width=0)
        Index Cond: (((to_account_id)::text = '00000000-0000-4000-8000-000000000000'::text) AND (created_at > (now() - '00:01:00'::interval)))
        Filter: (((status)::text <> ALL ('{failed,completed}'::text[])) AND ((from_account_id)::text = '00000000-0000-4000-8000-000000000000'::text) AND (amount = 100.00000000) AND ((currency)::text = 'USD'::text))
```
