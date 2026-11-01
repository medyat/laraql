<?php

declare(strict_types=1);

namespace Medyat\Laraql\Source;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Medyat\Laraql\Column\Column;
use Medyat\Laraql\Context;
use Medyat\Laraql\Input;

/**
 * A queryable source: an Eloquent model plus the whitelist of typed columns
 * clients may read, filter, sort and search. Application concerns (tenant
 * scopes, ability flags, photos) enter through `scope()`, `decorators()`,
 * `options()` and `params()`, never through the core. Hooks see the request
 * through an Input: the user, the validated params, the requested fields.
 */
abstract class Source
{
    /** The key clients use in `source`. */
    abstract public function name(): string;

    /** @return class-string<Model> */
    abstract public function model(): string;

    /**
     * Resolved once per query and never kept: sources are shared across requests
     * and tenants, so the list may depend on the request (columns generated from
     * tenant-defined variables). `Column::visibleWhen()` stays the way to hide a
     * declared column from some users.
     *
     * @return list<Column>
     */
    abstract public function columns(Context $context): array;

    /** One or two sentences for clients and agents: what a row is and when to query this source. */
    public function describe(): ?string
    {
        return null;
    }

    /** @return array<string, Relation> */
    public function relations(): array
    {
        return [];
    }

    /** @return array<string, Option> */
    public function options(): array
    {
        return [];
    }

    /**
     * Named inputs clients may send in `params`, typed like columns so the
     * validator checks them and clients can describe them. For values that shape
     * a join or a column (a balance date), not for narrowing rows.
     *
     * @return array<string, Column>
     */
    public function params(): array
    {
        return [];
    }

    /**
     * Order applied when the query sends no `sort`, column id → direction;
     * the key still breaks ties. Includes without `sort` use the related
     * source's default.
     *
     * @return array<string, 'asc'|'desc'>
     */
    public function defaultSort(): array
    {
        return [];
    }

    /**
     * Joins columns may read through, keyed by the table alias used in `from()`:
     * `['companies' => ['companies.id', '=', 'orders.company_id']]` or a closure
     * receiving the sub-query (and free to capture `$input`). Applied only when
     * a selected, filtered, sorted or searched column needs them.
     *
     * @return array<string, array{string, string, string}|Closure(Builder<Model>): void>
     */
    public function joins(Input $input): array
    {
        return [];
    }

    /**
     * Keys of `joins()` applied to every query, for joins `scope()` relies on.
     * A column using the same key does not join it twice.
     *
     * @return list<string>
     */
    public function alwaysJoin(): array
    {
        return [];
    }

    /**
     * Narrow every query on this source (tenant, ownership). Runs in the sub-query.
     *
     * @param  Builder<Model>  $query
     */
    public function scope(Builder $query, Input $input): void {}

    /**
     * Add keys to every row (ability flags, computed fields).
     *
     * @return list<Closure(Model, array<string, mixed>, Input): array<string, mixed>>
     */
    public function decorators(): array
    {
        return [];
    }

    /** Default: the model's policy `viewAny`, or allowed when there is no policy. */
    public function authorize(Context $context): bool
    {
        $model = $this->model();

        if (Gate::getPolicyFor($model) === null) {
            return true;
        }

        return Gate::forUser($context->user)->allows('viewAny', $model);
    }

    public function newModel(): Model
    {
        $class = $this->model();

        return new $class;
    }

    public function key(): string
    {
        return $this->newModel()->getKeyName();
    }

    public function table(): string
    {
        return $this->newModel()->getTable();
    }

    /**
     * Columns by id for this request; the key column is added when not declared.
     *
     * @return array<string, Column>
     */
    public function columnMap(Context $context): array
    {
        $map = [];

        foreach ($this->columns($context) as $column) {
            if (isset($map[$column->id])) {
                throw new LogicException("Source [{$this->name()}] declares column [{$column->id}] twice.");
            }

            $map[$column->id] = $column;
        }

        $key = $this->key();

        if (! isset($map[$key])) {
            $keyColumn = $this->newModel()->getKeyType() === 'int' ? Column::int($key) : Column::id($key);
            $map = [$key => $keyColumn] + $map;
        }

        return $map;
    }

    /**
     * Searchable columns the request's user may see.
     *
     * @param  array<string, Column>  $columns  This request's `columnMap()`.
     * @return list<Column>
     */
    public function searchableColumns(array $columns, Context $context): array
    {
        return array_values(array_filter($columns, fn (Column $column): bool => $column->searchable && $column->visible($context)));
    }
}
