<div align="center">
    <h1>Laraql</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/medyat/laraql"><img src="https://img.shields.io/packagist/v/medyat/laraql.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/medyat/laraql"><img src="https://img.shields.io/packagist/php-v/medyat/laraql.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/medyat/laraql"><img src="https://badge.laravel.cloud/badge/medyat/laraql?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/medyat/laraql/actions"><img alt="GitHub Workflow Status (production)" src="https://img.shields.io/github/actions/workflow/status/medyat/laraql/tests.yml?branch=production&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/medyat/laraql"><img src="https://img.shields.io/packagist/dt/medyat/laraql.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Typed, whitelisted list-query layer for Laravel: one JSON protocol for SPA tables and AI agents.

## Installation

You can install the package via Composer:

```bash
composer require medyat/laraql
```

You may publish all of the package's resources at once:

```bash
php artisan vendor:publish --tag="laraql"
```

Or, you may publish each resource individually:

### Publishing the Configuration File

```bash
php artisan vendor:publish --tag="laraql-config"
```

### Publishing the Translations

```bash
php artisan vendor:publish --tag="laraql-lang"
```

## Usage

Declare a source: the model, the typed columns clients may read, and what
else the source offers.

```php
use Medyat\Laraql\Column\Column;
use Medyat\Laraql\Source\{Option, Relation, Source};

final class OrderSource extends Source
{
    public function name(): string { return 'order'; }

    public function model(): string { return Order::class; }

    public function describe(): ?string { return 'Sales orders.'; }

    public function columns(Context $context): array
    {
        return [
            Column::id('code')->searchable()->describe('Order number, unique.'),
            Column::string('customer')->searchable(),
            Column::select('status', ['draft', 'released', 'done']),
            Column::money('gross_total', 'TRY'),
            Column::date('due_date'),
            Column::string('company.title')->from('companies.title'),
            Column::int('lines_count')->expression('(select count(*) from order_lines where order_lines.order_id = orders.id)')->notFilterable(),
        ];
    }

    public function joins(Input $input): array
    {
        return ['companies' => ['companies.id', '=', 'orders.company_id']];
    }

    public function relations(): array
    {
        return ['lines' => Relation::hasMany(OrderLineSource::class, 'order_id')];
    }

    public function options(): array
    {
        return ['only_done' => Option::scope(fn ($q) => $q->where('status', 'done'), 'Only finished orders.')];
    }
}
```

Register it in `config/laraql.php` (`sources`) or with `Registry::register()`,
then query `POST /laraql`:

```json
{ "queries": { "orders": { "source": "order", "fields": ["code", "gross_total"], "filters": [{ "column": "status", "op": "eq", "value": "done" }], "sort": [{ "column": "due_date", "dir": "desc" }], "page": { "number": 1, "size": 50 } } } }
```

`joins()` is for one-to-one and many-to-one lookups (a has-many join would
multiply parent rows; use `relations()` + `include` for those). The key is the
joined table's name; to alias a table or join it twice, use the closure form:
`'c' => fn ($q) => $q->leftJoin('companies as c', 'c.id', '=', 'orders.company_id')`.
A join `scope()` relies on (a visibility flag on the joined table) goes in
`alwaysJoin()` (`['companies']`): it is applied to every query, once.

Hooks (`scope`, `joins`, decorators, option closures) receive an `Input`: the
user, the requested field ids and the validated `params`. Declare params in
`params()` as typed columns (`['balance_as_of' => Column::date('balance_as_of')]`)
for inputs that shape a join or a column, such as a balance date; narrowing
rows is what filters are for.

A column a decorator reads but the client may leave out of `fields` (an
ability flag computed from `status`) is marked `->always()`: it is selected
for every rows query and returned only when requested.

A column some users must not see (prices for users without `show_price`) is
marked `->visibleWhen(fn (Context $c) => …)`: to the others it does not exist
(absent from omitted `fields`, skipped by search). An unknown or hidden column in
`fields` or `sort` is skipped and listed in the result's `ignored`; in `filters` it
is a 422, because dropping a filter would return too many rows.

`columns()` is resolved once per query and nothing keeps its result, so it may
depend on the request: a tenant's own variables can be added as columns. Sources
are shared across requests; keep request state out of their properties.

`defaultSort()` (`['position' => 'asc']`) orders queries that send no `sort`;
the primary key still breaks ties.

Laraql runs on SQLite, MySQL and PostgreSQL (CI replays every fixture on all
three) and supports integer and string/UUID primary keys.

The full contract is in [`docs/protocol.md`](docs/protocol.md); `fixtures/v1`
holds request/response pairs you can test a client against.
## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Laraql! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Hüseyin Akgül](https://github.com/medyat)
- [All Contributors](../../contributors)

## License

Laraql is open-sourced software licensed under the [MIT license](LICENSE.md).
