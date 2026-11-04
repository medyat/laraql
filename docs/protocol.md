# Laraql protocol v1 (draft)

Laraql is a typed, whitelisted list-query layer. A client sends one or more
**queries** against named **sources**; the server validates them against the
source's column definitions and answers with rows, a count or an error.

This document is the contract. `schema/laraql-v1.schema.json` validates it,
`fixtures/v1/` holds request/response pairs that both this package and client
adapters test against. Change this document before changing code.

## Transport

- `POST {route.path}` (default `laraql`), JSON body, JSON response.
- Authentication is the application's: add middleware in `config/laraql.php`.
- The request is a **batch**: several queries travel together and are
  answered under the same keys. One query failing never fails its siblings.

```json
{ "queries": { "orders": { "source": "order", "fields": ["code", "gross_total"] } } }
```

```json
{ "results": { "orders": { "rows": [ { "id": 1, "code": "SO-1", "gross_total": "120.00" } ], "page": { "number": 1, "size": 50, "hasMore": false } } } }
```

HTTP status is `200` whenever the envelope itself was valid; per-query
problems are reported inside `results`. The envelope is rejected as a whole
only when it is malformed (`422`) or the request is unauthenticated (`401`).

## Query

| Field | Type | Default | Notes |
|---|---|---|---|
| `source` | string | required | Source key, e.g. `order` |
| `kind` | `"rows"` \| `"count"` | `rows` | `count` ignores `fields`, `sort`, `page`, `include` |
| `fields` | string[] | all readable columns | Column ids; the key is always returned. Filter/sort columns and columns marked `always()` are read for the query but not returned unless listed |
| `filters` | Filter[] | `[]` | Combined with AND; at most `limits.filters` |
| `sort` | Sort[] | `[]` | At most `limits.sort`; empty → the source's default order, if it declares one; the primary key is always appended as tie-breaker |
| `page` | `{ number, size }` | `{ 1, limits.page_size.default }` | 1-based; `size ≤ limits.page_size.max` |
| `search` | string | — | Free text over the source's searchable columns |
| `include` | map | — | Laraql extension, see below |
| `options` | string[] | `[]` | Laraql extension: named hooks declared by the source |
| `params` | object | `{}` | Laraql extension: named, typed inputs declared by the source, `{ "name": value }`; see below |

### Filter

```json
{ "column": "gross_total", "op": "between", "value": [100, 500] }
```

Operators are 18 keys shared with the table client this protocol was designed
alongside. What an operator *means* depends on the column type; the validator rejects
combinations the type does not support with `422`.

| `op` | Value | Types | Semantics |
|---|---|---|---|
| `eq` / `neq` | scalar | string, number, bool, date | Exact match. On `date`/`datetime` the day range in the request timezone |
| `gt` `gte` `lt` `lte` | number; on money also a decimal string | number, money, star | Numeric comparison |
| `between` | `[min, max]` (either may be `null`); on money also decimal strings | number, money | Inclusive; a `null` edge makes it one-sided |
| `contains` / `not_contains` | string | string, text, id, email, url, phone | Case-insensitive substring; `%` and `_` are escaped |
| `starts_with` / `ends_with` | string | string, text, id, email, phone | Case-insensitive prefix / suffix |
| `in` / `not_in` | scalar[] (≤ `limits.in_values`); on `int` integers only | select, id, user, color, multiselect, string, email, url, phone, int | Exact, case-sensitive match against any / none of the values, like `eq`. Multi-valued columns: "contains any of" / "contains none of". Not on `text` or decimals |
| `before` / `after` | ISO date or `{ "preset": "today" }` | date, datetime | Strict: `before 2026-10-05` excludes Oct 5 |
| `within` | `{ "preset": … }` or `{ "from", "to" }` | date, datetime | Inclusive. Presets: `today`, `this_week`, `next_7_days`, `this_month`, `last_30_days`, `this_quarter`, `overdue` |
| `is_empty` / `is_not_empty` | — | all | `NULL`; for text types also `''`; for lists also `[]` |

Money values may be sent as decimal strings (`"300.50"`), the form money is
answered in, so a client can send back what it read without a float in
between; a JSON number is accepted too. A decimal string is an optional `-`,
digits and an optional `.` with digits; anything else is a `422`.

On `user` columns the value `"@me"` (with `eq`, `neq`, `in`, `not_in`) stands
for the authenticated user's id, so a saved filter "owner is me" works for
every user; without an authenticated user it is a `422`.

Presets and day ranges are resolved on the server in the **request
timezone**, so saved filters stay relative. The timezone travels as the
`X-Timezone` header (name configurable, `laraql.timezone.header`); without it
the application's `app.timezone` applies. An unknown zone name is an
envelope-level `422 invalid_request` (`errors.timezone`). All queries in a
batch share the same timezone.

### Search

`search` is free text over the columns the source declares `searchable()`
(text types only; the client cannot pick columns). The text is split on
whitespace into tokens; **every token must match at least one searchable
column** (tokens AND, columns OR), order does not matter, matching is a
case-insensitive substring with `%` and `_` escaped:

```sql
WHERE (code ILIKE '%ege%'  OR customer ILIKE '%ege%')
  AND (code ILIKE '%1004%' OR customer ILIKE '%1004%')
```

Blank search is ignored. More than `limits.search_tokens` tokens is `422`
(`errors.search`). A source without searchable columns rejects `search` with
`422`. `search` and `filters` combine with AND.

### Sort

```json
{ "column": "due_date", "dir": "desc" }
```

### Visibility

A source may show a column to some users only
(`Column::visibleWhen(fn (Context $c) => …)`). To anyone else the column does
not exist: it follows the unknown column rules below, it is absent when `fields`
is omitted, and search skips it. Access to the source as a whole is still
`authorize()`.

A source's column list is itself resolved per query
(`columns(Context $context)`), so it may differ between users or tenants, for
example columns generated from tenant-defined variables. A column the list does
not contain for this request behaves exactly like a hidden one. Nothing about
the list outlives the query.

### Unknown columns

A saved view can outlive a column: a tenant deletes a variable, a user loses a
permission. How the server answers depends on what a missing column would do to
the result:

| Where | Answer | Why |
|---|---|---|
| `fields`, `sort` | Skipped and listed in the result's `ignored` | The rows stay correct; a column is missing or the order falls back to the default |
| `filters` | `422` | Dropping a filter returns more rows than the client asked for |

The same applies inside `include`. `ignored` holds request paths
(`["fields.3", "sort.0", "include.lines.fields.1"]`) and is present only when
something was skipped. It does not distinguish a hidden column from one that
never existed. A sort on a column that exists but is not sortable is still `422`,
and so is an id that is not a string.

### Result

| Field | Present when | Shape |
|---|---|---|
| `rows` | `kind: rows` | `object[]`; dotted column ids are nested (`company.title` → `{ "company": { "title" } }`) |
| `page` | `kind: rows` | `{ number, size, hasMore }`; `hasMore` is computed by fetching `size + 1` rows, no count query runs |
| `total` | `kind: count` | integer |
| `ignored` | something was skipped, see Unknown columns | `string[]` of request paths |
| `error` | the query failed | an RFC 9457 problem object, see Errors |

A client that never asks for `kind: count` never pays for a count.

## Include (Laraql extension)

```json
{ "source": "order", "include": { "lines": { "fields": ["product_code", "qty"], "sort": [{ "column": "position", "dir": "asc" }], "limit": 50 } } }
```

- A relation must be declared on the source; the related source is itself a
  laraql source, so its column whitelist and authorization apply.
- `limit` per parent row, default `limits.include.limit`, max
  `limits.include.max_limit`; no pagination inside includes.
- Nesting depth at most `limits.include.depth`.
- Included rows land on the parent row under the relation key.
- Missing authorization on the related source is a `403` for the whole
  query, never a silently empty relation.

## Options (Laraql extension)

Named, server-side behaviours a source declares; the client only sends names:

```json
{ "source": "product", "options": ["only_orderable", "with_photo"] }
```

A source lists its options in `options(): array`; each entry is one of three
kinds, so a client can say what it does:

| Kind | Effect | Example |
|---|---|---|
| `Option::scope(fn)` | narrows the row set in the sub-query | `only_orderable` → `where('type', …)` |
| `Option::load(relation)` | eager-loads a relation for the page of rows; the `with()` array form adds constraints. The model's own `$with` is never applied | `with_photo`, `['lines' => fn ($q) => …]` |
| `Option::decorate(fn)` | adds computed keys to each row | `allowed_units` → `{ "allowed_units": [...] }` |

Unknown names are `422` (`options.N`). Options are the replacement for ad-hoc
"function" names: they are part of the contract and carry a description.

## Parameters (Laraql extension)

Inputs that are not filters: they change how the source builds a column or a
join (a balance as of a date, a unit to convert to) rather than which rows
match.

```json
{ "source": "company", "fields": ["code", "balance"], "params": { "balance_as_of": "2026-09-30" } }
```

- A source declares them in `params(): array` as typed columns
  (`'balance_as_of' => Column::date('balance_as_of')`), with a `describe()` like
  any column.
- One scalar per name, validated like an `eq` value of that type; `"@me"`
  resolves on `user` params. Unknown names and unfit values are `422` under
  `params.<name>`.
- Hooks (`scope`, `joins`, decorators, option closures) read them from
  `Input::$params`, next to the user and the requested field ids.
- They apply to `kind: count` too; includes carry no params.

## Errors

Errors are [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) problem
objects:

| Member | Content |
|---|---|
| `type` | `https://github.com/medyat/laraql/blob/production/docs/protocol.md#` + the type name below; links to its heading here. Clients compare the whole string |
| `title` | Fixed short name of the type (`Invalid query`); localizable, never varies otherwise |
| `status` | The HTTP status the problem stands for |
| `detail` | Human-readable explanation of this occurrence |
| `errors` | Only when there are path-level problems: request path → messages, e.g. `{ "filters.2.op": ["…"] }`, `{ "page.size": ["…"] }` |

A per-query problem sits under the query's key as `error` and never changes
the HTTP status (`200`); its siblings are answered normally:

```json
{ "results": { "orders": { "error": {
  "type": "https://github.com/medyat/laraql/blob/production/docs/protocol.md#invalid_query",
  "title": "Invalid query",
  "status": 422,
  "detail": "The query is invalid.",
  "errors": { "filters.0.op": ["Operator \"contains\" is not allowed on column \"gross_total\" (money)."] }
} } } }
```

An unusable envelope is the only non-200 answer from laraql: the body is the
problem object itself (no `results`), sent as `application/problem+json`.

`title`, `detail` and the messages in `errors` come from
`lang/en/messages.php` and are publishable.

### `unknown_source`

`404`, per query: the `source` key is not registered.

### `forbidden`

`403`, per query: the source's `authorize()` denies the user, or an included
source does.

### `invalid_query`

`422`, per query, with `errors`: unknown column in `filters`, unknown operator
/ option / relation / param, type–operator mismatch, an unfit value, a limit
exceeded.

### `invalid_request`

`422`, envelope, with `errors`: no `queries`, an empty map, a query key that is
not an identifier, more than `limits.queries_per_request` queries, an unknown
timezone (`errors.timezone`), or a body that is not an object.

### Authentication

`401` comes from the application's authentication middleware, not from
laraql, so its body is the application's. An application serving laraql
clients should answer it as a problem object as well.

## Value representation

- `int` → JSON integer; `float`, `percentage` → JSON number (the server
  casts database decimals); `bool` → JSON boolean.
- `money` → JSON string: a decimal with exactly the column's scale (default 2,
  `Column::money(…)->scale(n)`), `.` as separator, no exponent and no
  grouping: `"300.50"`, `"-12.00"`. Exact database decimals are rounded half
  up to the scale as text, never through a float; the answer is the same on
  every database.
- `date` → `YYYY-MM-DD`; `datetime` → ISO 8601 in UTC with `Z`.
- Empty values are `null`; text columns may also hold `''`, list columns `[]`.
- Dotted column ids are nested in rows: `company.title` → `{ "company": { "title": "…" } }`.

## Fixtures

`fixtures/v1/*.json` are the executable form of this document, computed
against `fixtures/v1/dataset.json`; see the README there for the file format.
A behaviour that is not pinned by a fixture is not part of the contract.

## Limits (defaults)

See `config/laraql.php`: page size 50 / max 500, 20 filters, 500 values per
`in`, 3 sort keys, include limit 100 / max 500 / depth 2, 20 queries per
request.

## Reserved

- `page.cursor`: keyset pagination is not part of v1. The name is reserved so
  it can be added in a minor version next to `page.number`; offset paging stays.
