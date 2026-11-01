<?php

declare(strict_types=1);

namespace Medyat\Laraql\Query\Compiler;

use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;
use Medyat\Laraql\Column\Column;
use Medyat\Laraql\Input;
use Medyat\Laraql\Query\Condition;
use Medyat\Laraql\Query\Sort;
use Medyat\Laraql\Source\Option;
use Medyat\Laraql\Source\Source;
use Medyat\Laraql\Support\Sql;

/**
 * Builds the two-layer query: the sub-query selects the whitelisted columns
 * under their aliases (plain columns, joined columns, expressions) and takes
 * the filters that can be pushed down; the outer query runs over the
 * sub-query's aliases for everything else, then sorts and pages.
 */
final readonly class QueryCompiler
{
    public function __construct(private FilterCompiler $filters) {}

    /**
     * @param  array<string, Column>  $columns  The source's columns for this request.
     * @param  list<Column>  $select  Columns the sub-query must expose (output + filter/sort/relation keys).
     * @param  list<Condition>  $conditions
     * @param  list<Sort>  $sort
     * @param  list<string>  $searchTokens
     * @param  list<string>  $options
     * @return Builder<Model>
     */
    public function build(Source $source, array $columns, array $select, array $conditions, array $sort, array $searchTokens, array $options, Input $input): Builder
    {
        $table = $source->table();
        $inner = $source->newModel()->newQuery();
        $source->scope($inner, $input);

        foreach ($options as $name) {
            $option = $source->options()[$name];

            if ($option->kind === Option::SCOPE && $option->scope !== null) {
                ($option->scope)($inner, $input);
            }
        }

        $searchable = $searchTokens === [] ? [] : $source->searchableColumns($columns, $input);
        $this->applyJoins($inner, $source, [...$select, ...array_map(fn (Condition $c): Column => $c->column, $conditions), ...$searchable], $input);
        $inner->select(array_map(fn (Column $column) => $this->selectExpression($column, $table), $select));

        $dates = new DateRange($input);
        $deferred = [];

        foreach ($conditions as $condition) {
            $condition->column->pushable()
                ? $this->filters->apply($inner, $condition->column->qualified($table), $condition, $dates)
                : $deferred[] = $condition;
        }

        $searchPushable = $searchable !== [] && array_all($searchable, fn (Column $column): bool => $column->pushable());

        if ($searchPushable) {
            $this->filters->search($inner, array_map(fn (Column $column): string => $column->qualified($table), $searchable), $searchTokens);
        }

        // newModelQuery: global scopes already ran in the sub-query, and the model's own $with is not applied (eager loads come only from load options).
        $outer = $source->newModel()->newModelQuery()->fromSub($inner, $table);

        foreach ($deferred as $condition) {
            $this->filters->apply($outer, $condition->column->alias(), $condition, $dates);
        }

        if ($searchable !== [] && ! $searchPushable) {
            $this->filters->search($outer, array_map(fn (Column $column): string => $column->alias(), $searchable), $searchTokens);
        }

        foreach ($options as $name) {
            $option = $source->options()[$name];

            if ($option->kind === Option::LOAD && $option->relation !== null) {
                $outer->with($option->relation);
            }
        }

        $this->orderBy($outer, $sort, $columns[$source->key()]);

        return $outer;
    }

    /**
     * Left-joins the source's always-on joins and every table a used column reads through, each once.
     *
     * @param  Builder<Model>  $inner
     * @param  list<Column>  $columns
     */
    private function applyJoins(Builder $inner, Source $source, array $columns, Input $input): void
    {
        $joins = $source->joins($input);
        $applied = [];
        $keys = [...$source->alwaysJoin(), ...array_filter(array_map(fn (Column $column): ?string => $column->join, $columns))];

        foreach ($keys as $key) {
            if (isset($applied[$key])) {
                continue;
            }

            $join = $joins[$key] ?? throw new LogicException("Source [{$source->name()}] needs join [{$key}], which joins() does not declare.");
            $applied[$key] = true;

            $join instanceof Closure ? $join($inner) : $inner->leftJoin($key, ...$join);
        }
    }

    /**
     * Deterministic order: the requested keys, then the primary key ascending.
     *
     * @param  Builder<Model>  $query
     * @param  list<Sort>  $sort
     */
    private function orderBy(Builder $query, array $sort, Column $key): void
    {
        $keyed = false;

        foreach ($sort as $item) {
            $query->orderBy($item->column->alias(), $item->dir);
            $keyed = $keyed || $item->column->id === $key->id;
        }

        if (! $keyed) {
            $query->orderBy($key->alias(), 'asc');
        }
    }

    private function selectExpression(Column $column, string $table): string|Expression
    {
        $alias = $column->alias();

        if ($column->expression !== null) {
            $grammar = DB::connection()->getQueryGrammar();

            return Sql::raw('('.$column->expression.') as '.$grammar->wrap($alias));
        }

        return "{$column->qualified($table)} as {$alias}";
    }
}
