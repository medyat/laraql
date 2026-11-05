<?php

declare(strict_types=1);

namespace Medyat\Laraql\Query;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Medyat\Laraql\Column\Column;
use Medyat\Laraql\Context;
use Medyat\Laraql\Filter\Op;
use Medyat\Laraql\Input;
use Medyat\Laraql\Query\Compiler\QueryCompiler;
use Medyat\Laraql\Result\RowFormatter;
use Medyat\Laraql\Source\Option;
use Medyat\Laraql\Source\Source;
use Medyat\Laraql\Support\Raw;

/**
 * Runs a validated query: rows (with `hasMore` from one extra row and the
 * includes attached per parent) or a count.
 */
final readonly class Executor
{
    public function __construct(
        private QueryCompiler $compiler,
        private RowFormatter $formatter,
    ) {}

    /** @return array{rows: list<array<string, mixed>>, page: array{number: int, size: int, hasMore: bool}} */
    public function rows(Query $query, Context $context): array
    {
        $output = $this->output($query->source, $query->columns, $query->fields, $context);
        $input = new Input($context, $query->params, $this->ids($output));
        $select = $this->select($query->columns, $output, $query->filters, $query->sort, [
            ...array_map(
                fn (IncludeQuery $include): Column => $this->localKey($query->source, $query->columns, $include),
                array_values($query->include),
            ),
            ...$this->searchedOnOuter($query, $context),
        ]);

        $models = $this->compiler
            ->build($query->source, $query->columns, $select, $query->filters, $query->sort, $query->searchTokens, $query->options, $input)
            ->offset($query->page->offset())
            ->limit($query->page->size + 1)
            ->get();

        $hasMore = $models->count() > $query->page->size;
        $models = $models->take($query->page->size);

        $rows = $this->formatAll($models, $output, $query->source, $query->options, $input);
        $this->attachIncludes($rows, $models, $query->source, $query->columns, $query->include, $context);

        return [
            'rows' => $rows,
            'page' => ['number' => $query->page->number, 'size' => $query->page->size, 'hasMore' => $hasMore],
        ];
    }

    public function count(Query $query, Context $context): int
    {
        $select = $this->select($query->columns, [$query->columns[$query->source->key()]], $query->filters, [], $this->searchedOnOuter($query, $context));

        return $this->compiler
            ->build($query->source, $query->columns, $select, $query->filters, [], $query->searchTokens, $query->options, new Input($context, $query->params))
            ->count();
    }

    /**
     * Searchable columns the outer query searches by alias: once one of them is an expression, the
     * search cannot be pushed down (QueryCompiler::build()), so the sub-query must select them all.
     *
     * @return list<Column>
     */
    private function searchedOnOuter(Query $query, Context $context): array
    {
        if ($query->searchTokens === []) {
            return [];
        }

        $searchable = $query->source->searchableColumns($query->columns, $context);

        return array_all($searchable, fn (Column $column): bool => $column->pushable()) ? [] : $searchable;
    }

    /**
     * @param  list<Column>  $columns
     * @return list<string>
     */
    private function ids(array $columns): array
    {
        return array_map(fn (Column $column): string => $column->id, $columns);
    }

    /**
     * Output columns: the key first, then the requested fields (or every column the user may see).
     *
     * @param  array<string, Column>  $columns
     * @param  list<Column>|null  $fields
     * @return list<Column>
     */
    private function output(Source $source, array $columns, ?array $fields, Context $context): array
    {
        $output = [$columns[$source->key()]];

        foreach ($fields ?? array_filter($columns, fn (Column $column): bool => $column->visible($context)) as $column) {
            if ($column->id !== $source->key()) {
                $output[] = $column;
            }
        }

        return $output;
    }

    /**
     * Columns the sub-query must expose: output + filtered + sorted + relation keys + those marked `always()`, each once.
     *
     * @param  array<string, Column>  $columns
     * @param  list<Column>  $output
     * @param  list<Condition>  $filters
     * @param  list<Sort>  $sort
     * @param  list<Column>  $extra
     * @return list<Column>
     */
    private function select(array $columns, array $output, array $filters, array $sort, array $extra): array
    {
        $always = array_values(array_filter($columns, fn (Column $column): bool => $column->always));
        $byId = [];

        foreach ([...$output, ...array_map(fn (Condition $c): Column => $c->column, $filters), ...array_map(fn (Sort $s): Column => $s->column, $sort), ...$extra, ...$always] as $column) {
            $byId[$column->id] ??= $column;
        }

        return array_values($byId);
    }

    /**
     * @param  Collection<int, Model>  $models
     * @param  list<Column>  $output
     * @param  list<string>  $options
     * @return list<array<string, mixed>>
     */
    private function formatAll(Collection $models, array $output, Source $source, array $options, Input $input): array
    {
        $decorators = $source->decorators();

        foreach ($options as $name) {
            $option = $source->options()[$name];

            if ($option->kind === Option::DECORATE && $option->decorator !== null) {
                $decorators[] = $option->decorator;
            }
        }

        return array_values($models->map(fn (Model $model): array => $this->formatter->format($model, $output, $decorators, $input))->all());
    }

    /**
     * Loads every include with one query per relation and nests the related
     * rows under the relation key, `limit` per parent.
     *
     * shortcut: the per-parent limit is applied in PHP after fetching every
     * related row of the page; switch to a window function (ROW_NUMBER OVER
     * PARTITION BY) when parents × related rows grows large.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<int, Model>  $models
     * @param  array<string, Column>  $columns
     * @param  array<string, IncludeQuery>  $includes
     */
    private function attachIncludes(array &$rows, Collection $models, Source $source, array $columns, array $includes, Context $context): void
    {
        foreach ($includes as $include) {
            $localKey = $this->localKey($source, $columns, $include);
            $foreignKey = $include->columns[$include->relation->foreignKey]
                ?? throw new LogicException("Relation [{$include->name}] of source [{$source->name()}] points at undeclared column [{$include->relation->foreignKey}].");

            $parentKeys = $models->map(fn (Model $model): mixed => $model->getAttribute($localKey->alias()))->filter()->unique()->values()->all();
            $output = $this->output($include->source, $include->columns, $include->fields, $context);
            $input = new Input($context, [], $this->ids($output));
            $filters = [...$include->filters, new Condition($foreignKey, Op::In, $parentKeys)];
            $select = $this->select($include->columns, $output, $filters, $include->sort, [
                $foreignKey,
                ...array_map(fn (IncludeQuery $nested): Column => $this->localKey($include->source, $include->columns, $nested), array_values($include->include)),
            ]);

            $related = $parentKeys === []
                ? new Collection
                : $this->compiler->build($include->source, $include->columns, $select, $filters, $include->sort, [], [], $input)->get();

            $grouped = $related->groupBy(fn (Model $model): string => Raw::string($model->getAttribute($foreignKey->alias())));

            foreach ($models->values() as $i => $model) {
                $parent = Raw::string($model->getAttribute($localKey->alias()));
                /** @var Collection<int, Model> $children */
                $children = ($grouped[$parent] ?? new Collection)->take($include->limit)->values();
                $childRows = $this->formatAll($children, $output, $include->source, [], $input);
                $this->attachIncludes($childRows, $children, $include->source, $include->columns, $include->include, $context);
                $rows[$i][$include->name] = $childRows;
            }
        }
    }

    /** @param  array<string, Column>  $columns */
    private function localKey(Source $source, array $columns, IncludeQuery $include): Column
    {
        $id = $include->relation->localKey ?? $source->key();

        return $columns[$id]
            ?? throw new LogicException("Relation [{$include->name}] of source [{$source->name()}] uses undeclared local key [{$id}].");
    }
}
