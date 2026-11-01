# Release Notes

## [Unreleased](https://github.com/medyat/laraql-php/commits/production)

Everything below ships as the first pre-release, `v0.1.0`.

### Protocol v1

- One JSON query language for lists: `source`, `fields`, `filters` (18 typed operators), `sort`, `page`, `search`, `kind: count`, plus the laraql extensions `include`, `options` and `params` (named, typed inputs that shape a join or a column, such as a balance date).
- Batch envelope `{ queries }` → `{ results }`; a failing query answers an `error` under its own key and never fails its siblings.
- `docs/protocol.md` is the contract, `schema/laraql-v1.schema.json` validates it, `fixtures/v1` holds 77 request/response pairs computed against a canonical dataset.
- Date presets and day ranges resolve in the request timezone (`X-Timezone` header); `"@me"` on user columns resolves to the authenticated user.

### Package

- Typed sources: `Column::{int,float,money,…,date,datetime,json}` with per-type operators, `searchable()`, `expression()`, `from()` through declared `joins()`, `describe()`.
- `Source` with relations (`include`), options (`Option::scope|load|decorate`), params (`params()`), scope and decorator hooks, policy-based authorization; integer and string/UUID keys. Hooks receive an `Input` (user, params, requested field ids).
- `Column::always()`: selected on every rows query (not returned unless requested) so decorators can read it whatever `fields` says.
- `Column::visibleWhen()`: per-user visibility evaluated per request; a hidden column is unknown to that user, absent from omitted `fields` and skipped by search. Unknown columns in `fields` and `sort` are skipped and listed in `ignored`; in `filters` they are 422. `Source::authorize()` takes the `Context`.
- `Source::defaultSort()`: the order used when a query (or an include) sends no `sort`; the key remains the tie-breaker.
- `Option::load()` takes the `with()` array form for constrained eager loads; the outer query ignores the model's own `$with`, so eager loads come only from load options.
- `Source::alwaysJoin()`: joins applied to every query (for conditions in `scope()`), never duplicated when a column uses the same key.
- Validator reporting every problem under its path (`filters.2.op`), with configurable limits (page size, filters, sort keys, `in` values, search tokens, include limit/depth, queries per request).
- Two-layer compiler: real columns are filtered and searched inside the sub-query, expression columns on the outer query; deterministic ordering with the primary key as tie-breaker; `hasMore` without a count query.
- Runs on SQLite, MySQL and PostgreSQL (CI replays every fixture on all three). Requires PHP 8.4 and Laravel 13.
