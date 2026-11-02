<?php

declare(strict_types=1);

namespace Medyat\Laraql\Query;

use LogicException;
use Medyat\Laraql\Column\Column;
use Medyat\Laraql\Column\Type;
use Medyat\Laraql\Context;
use Medyat\Laraql\Filter\Op;
use Medyat\Laraql\Query\Compiler\DateRange;
use Medyat\Laraql\Source\Registry;
use Medyat\Laraql\Source\Source;

/**
 * Turns a raw query array into a Query, or throws InvalidQueryException with
 * every problem keyed by its path (`filters.2.op`). Shape and semantics are
 * checked together: a column must exist, be filterable and accept the
 * operator, and the value must fit the operator. The exception is an unknown
 * column in `fields` or `sort`: a saved view can outlive a column, and skipping
 * it leaves the matching rows intact, so it is listed in `ignored` instead. A
 * filter is never skipped, that would return more rows than were asked for.
 */
final class Validator
{
    private const array QUERY_KEYS = ['source', 'kind', 'fields', 'filters', 'sort', 'page', 'search', 'include', 'options', 'params'];

    private const array INCLUDE_KEYS = ['fields', 'filters', 'sort', 'limit', 'include'];

    private Context $context;

    /** @var array<class-string<Source>, array<string, Column>> Column lists of the query being validated, one per source. */
    private array $columnMaps = [];

    /** @var list<string> Request paths skipped in the query being validated. */
    private array $ignored = [];

    /** @param  array<string, mixed>  $limits  The `laraql.limits` config. */
    public function __construct(
        private readonly Registry $registry,
        private readonly array $limits,
    ) {}

    /** @param  array<string, mixed>  $raw */
    public function validate(array $raw, Source $source, Context $context): Query
    {
        $this->context = $context;
        $this->columnMaps = [];
        $this->ignored = [];
        $errors = new Errors;
        $this->unknownKeys($raw, self::QUERY_KEYS, '', $errors);

        $kind = $raw['kind'] ?? 'rows';

        if (! in_array($kind, ['rows', 'count'], true)) {
            $errors->add('kind', 'invalid_value', ['field' => 'kind']);
            $kind = 'rows';
        }

        $query = new Query(
            source: $source,
            columns: $this->columnMap($source),
            kind: $kind,
            fields: $this->fields($raw['fields'] ?? null, $source, 'fields', $errors),
            filters: $this->filters($raw['filters'] ?? [], $source, 'filters', $errors),
            sort: $this->sort($raw['sort'] ?? [], $source, 'sort', $errors),
            page: $this->page($raw['page'] ?? [], $errors),
            searchTokens: $this->search($raw['search'] ?? null, $source, $errors),
            include: $this->include($raw['include'] ?? [], $source, 'include', 1, $errors),
            options: $this->options($raw['options'] ?? [], $source, 'options', $errors),
            params: $this->params($raw['params'] ?? [], $source, 'params', $errors),
            ignored: $this->ignored,
        );

        $errors->throwIfAny();

        return $query;
    }

    /**
     * @param  array<mixed>  $raw
     * @param  list<string>  $allowed
     */
    private function unknownKeys(array $raw, array $allowed, string $prefix, Errors $errors): void
    {
        foreach (array_keys($raw) as $key) {
            if (! in_array((string) $key, $allowed, true)) {
                $errors->add($prefix.$key, 'unknown_key', ['key' => (string) $key]);
            }
        }
    }

    /** @return list<Column>|null */
    private function fields(mixed $raw, Source $source, string $path, Errors $errors): ?array
    {
        if ($raw === null) {
            return null;
        }

        if (! is_array($raw) || ! array_is_list($raw)) {
            $errors->add($path, 'invalid_value', ['field' => $path]);

            return null;
        }

        $fields = [];

        foreach ($raw as $i => $id) {
            $column = $this->column($id, $source);

            if ($column === null) {
                $this->skipOrReject($id, "{$path}.{$i}", "{$path}.{$i}", $source, $errors);

                continue;
            }

            $fields[] = $column;
        }

        return $fields;
    }

    /** @return list<Condition> */
    private function filters(mixed $raw, Source $source, string $path, Errors $errors): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            $errors->add($path, 'invalid_value', ['field' => $path]);

            return [];
        }

        $max = $this->limit('filters');

        if (count($raw) > $max) {
            $errors->add($path, 'limit_exceeded', ['field' => $path, 'max' => $max]);

            return [];
        }

        $conditions = [];

        foreach ($raw as $i => $filter) {
            $condition = $this->filter($filter, $source, "{$path}.{$i}", $errors);

            if ($condition !== null) {
                $conditions[] = $condition;
            }
        }

        return $conditions;
    }

    private function filter(mixed $raw, Source $source, string $path, Errors $errors): ?Condition
    {
        if (! is_array($raw)) {
            $errors->add($path, 'invalid_value', ['field' => $path]);

            return null;
        }

        $this->unknownKeys($raw, ['column', 'op', 'value', 'currency'], $path.'.', $errors);

        $id = $raw['column'] ?? null;
        $column = $this->column($id, $source);

        if ($column === null) {
            $errors->add("{$path}.column", 'unknown_column', ['column' => is_scalar($id) ? (string) $id : '?', 'source' => $source->name()]);

            return null;
        }

        if (! $column->filterable) {
            $errors->add("{$path}.column", 'column_not_filterable', ['column' => $column->id]);

            return null;
        }

        $op = is_string($raw['op'] ?? null) ? Op::tryFrom($raw['op']) : null;

        if ($op === null) {
            $errors->add("{$path}.op", 'invalid_value', ['field' => "{$path}.op"]);

            return null;
        }

        if (! $column->type->allows($op)) {
            $errors->add("{$path}.op", 'operator_not_allowed', ['op' => $op->value, 'column' => $column->id, 'type' => $column->type->value]);

            return null;
        }

        if (! $op->expectsValue()) {
            return new Condition($column, $op);
        }

        $value = $raw['value'] ?? null;

        if (! $this->valueFits($op, $column, $value)) {
            $errors->add("{$path}.value", 'invalid_value', ['field' => "{$path}.value"]);

            return null;
        }

        if ($column->type === Type::User) {
            $value = $this->resolveMe($value, "{$path}.value", $errors);
        }

        return new Condition($column, $op, $value);
    }

    /**
     * `"@me"` on a user column stands for the authenticated user's id, so saved
     * filters such as "owner is me" work for everyone. Without a user: 422.
     */
    private function resolveMe(mixed $value, string $path, Errors $errors): mixed
    {
        $values = is_array($value) ? $value : [$value];

        if (! in_array('@me', $values, true)) {
            return $value;
        }

        $id = $this->context->user?->getAuthIdentifier();

        if ($id === null) {
            $errors->add($path, 'me_requires_user');

            return $value;
        }

        $resolved = array_map(fn (mixed $v): mixed => $v === '@me' ? $id : $v, $values);

        return is_array($value) ? array_values($resolved) : $resolved[0];
    }

    private function valueFits(Op $op, Column $column, mixed $value): bool
    {
        return match ($op) {
            Op::Eq, Op::Neq => $column->type->isDate() ? $this->isDate($value) : is_scalar($value),
            Op::Gt, Op::Gte, Op::Lt, Op::Lte => $this->isNumber($value, $column),
            Op::Between => is_array($value) && array_is_list($value) && count($value) === 2
                && ($value[0] === null || $this->isNumber($value[0], $column))
                && ($value[1] === null || $this->isNumber($value[1], $column))
                && ! ($value[0] === null && $value[1] === null),
            Op::Contains, Op::NotContains, Op::StartsWith, Op::EndsWith => is_string($value) && $value !== '',
            Op::In, Op::NotIn => is_array($value) && array_is_list($value) && $value !== []
                && count($value) <= $this->limit('in_values')
                && array_all($value, fn (mixed $v): bool => is_string($v) || is_int($v)),
            Op::Before, Op::After => $this->isDate($value) || $value === ['preset' => 'today'],
            Op::Within => (is_array($value) && array_keys($value) === ['preset'] && is_string($value['preset']) && in_array($value['preset'], DateRange::PRESETS, true))
                || (is_array($value) && array_keys($value) === ['from', 'to'] && $this->isDate($value['from']) && $this->isDate($value['to'])),
            Op::IsEmpty, Op::IsNotEmpty => true,
        };
    }

    /** A JSON number, or on money a decimal string (the form money is answered in). */
    private function isNumber(mixed $value, Column $column): bool
    {
        return is_int($value) || is_float($value)
            || ($column->type === Type::Money && is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value) === 1);
    }

    private function isDate(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})?)?$/', $value) === 1
            && strtotime($value) !== false;
    }

    /** @return list<Sort> */
    private function sort(mixed $raw, Source $source, string $path, Errors $errors): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            $errors->add($path, 'invalid_value', ['field' => $path]);

            return [];
        }

        $max = $this->limit('sort');

        if (count($raw) > $max) {
            $errors->add($path, 'limit_exceeded', ['field' => $path, 'max' => $max]);

            return [];
        }

        $sorts = [];

        foreach ($raw as $i => $item) {
            if (! is_array($item)) {
                $errors->add("{$path}.{$i}", 'invalid_value', ['field' => "{$path}.{$i}"]);

                continue;
            }

            $this->unknownKeys($item, ['column', 'dir'], "{$path}.{$i}.", $errors);

            $id = $item['column'] ?? null;
            $column = $this->column($id, $source);

            if ($column === null) {
                $this->skipOrReject($id, "{$path}.{$i}", "{$path}.{$i}.column", $source, $errors);

                continue;
            }

            if (! $column->sortable) {
                $errors->add("{$path}.{$i}.column", 'column_not_sortable', ['column' => $column->id]);

                continue;
            }

            $dir = $item['dir'] ?? null;

            if ($dir !== 'asc' && $dir !== 'desc') {
                $errors->add("{$path}.{$i}.dir", 'invalid_value', ['field' => "{$path}.{$i}.dir"]);

                continue;
            }

            $sorts[] = new Sort($column, $dir);
        }

        return $sorts === [] ? $this->defaultSort($source) : $sorts;
    }

    /**
     * The source's own order for queries without `sort`.
     *
     * @return list<Sort>
     */
    private function defaultSort(Source $source): array
    {
        $sorts = [];

        foreach ($source->defaultSort() as $id => $dir) {
            $column = $this->columnMap($source)[$id] ?? throw new LogicException("Source [{$source->name()}] default sort uses undeclared column [{$id}].");
            $sorts[] = new Sort($column, $dir);
        }

        return $sorts;
    }

    private function page(mixed $raw, Errors $errors): Page
    {
        $defaults = $this->limits['page_size'] ?? [];
        $default = is_array($defaults) && is_int($defaults['default'] ?? null) ? $defaults['default'] : 50;
        $max = is_array($defaults) && is_int($defaults['max'] ?? null) ? $defaults['max'] : 500;

        if (! is_array($raw)) {
            $errors->add('page', 'invalid_value', ['field' => 'page']);

            return new Page(1, $default);
        }

        $this->unknownKeys($raw, ['number', 'size'], 'page.', $errors);

        $number = $raw['number'] ?? 1;
        $size = $raw['size'] ?? $default;

        if (! is_int($number) || $number < 1) {
            $errors->add('page.number', 'invalid_value', ['field' => 'page.number']);
            $number = 1;
        }

        if (! is_int($size) || $size < 1) {
            $errors->add('page.size', 'invalid_value', ['field' => 'page.size']);
            $size = $default;
        } elseif ($size > $max) {
            $errors->add('page.size', 'limit_exceeded', ['field' => 'page.size', 'max' => $max]);
            $size = $max;
        }

        return new Page($number, $size);
    }

    /** @return list<string> */
    private function search(mixed $raw, Source $source, Errors $errors): array
    {
        if ($raw === null) {
            return [];
        }

        if (! is_string($raw)) {
            $errors->add('search', 'invalid_value', ['field' => 'search']);

            return [];
        }

        $tokens = preg_split('/\s+/u', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($tokens === []) {
            return [];
        }

        if ($source->searchableColumns($this->columnMap($source), $this->context) === []) {
            $errors->add('search', 'search_not_supported', ['source' => $source->name()]);

            return [];
        }

        $max = $this->limit('search_tokens');

        if (count($tokens) > $max) {
            $errors->add('search', 'limit_exceeded', ['field' => 'search', 'max' => $max]);

            return [];
        }

        return $tokens;
    }

    /** @return array<string, IncludeQuery> */
    private function include(mixed $raw, Source $source, string $path, int $depth, Errors $errors): array
    {
        if ($raw === []) {
            return [];
        }

        if (! is_array($raw) || array_is_list($raw)) {
            $errors->add($path, 'invalid_value', ['field' => $path]);

            return [];
        }

        $includeLimits = is_array($this->limits['include'] ?? null) ? $this->limits['include'] : [];
        $maxDepth = is_int($includeLimits['depth'] ?? null) ? $includeLimits['depth'] : 2;
        $defaultLimit = is_int($includeLimits['limit'] ?? null) ? $includeLimits['limit'] : 100;
        $maxLimit = is_int($includeLimits['max_limit'] ?? null) ? $includeLimits['max_limit'] : 500;

        $includes = [];

        foreach ($raw as $name => $entry) {
            $name = (string) $name;
            $entryPath = "{$path}.{$name}";
            $relation = $source->relations()[$name] ?? null;

            if ($relation === null) {
                $errors->add($entryPath, 'unknown_relation', ['relation' => $name, 'source' => $source->name()]);

                continue;
            }

            if ($depth > $maxDepth) {
                $errors->add($entryPath, 'include_too_deep', ['depth' => $maxDepth]);

                continue;
            }

            if (! is_array($entry) || array_is_list($entry) && $entry !== []) {
                $errors->add($entryPath, 'invalid_value', ['field' => $entryPath]);

                continue;
            }

            $this->unknownKeys($entry, self::INCLUDE_KEYS, $entryPath.'.', $errors);

            $related = $this->registry->ofClass($relation->source);
            $limit = $entry['limit'] ?? $defaultLimit;

            if (! is_int($limit) || $limit < 1) {
                $errors->add("{$entryPath}.limit", 'invalid_value', ['field' => "{$entryPath}.limit"]);
                $limit = $defaultLimit;
            } elseif ($limit > $maxLimit) {
                $errors->add("{$entryPath}.limit", 'limit_exceeded', ['field' => "{$entryPath}.limit", 'max' => $maxLimit]);
                $limit = $maxLimit;
            }

            $includes[$name] = new IncludeQuery(
                name: $name,
                relation: $relation,
                source: $related,
                columns: $this->columnMap($related),
                fields: $this->fields($entry['fields'] ?? null, $related, "{$entryPath}.fields", $errors),
                filters: $this->filters($entry['filters'] ?? [], $related, "{$entryPath}.filters", $errors),
                sort: $this->sort($entry['sort'] ?? [], $related, "{$entryPath}.sort", $errors),
                limit: $limit,
                include: $this->include($entry['include'] ?? [], $related, "{$entryPath}.include", $depth + 1, $errors),
            );
        }

        return $includes;
    }

    /** @return list<string> */
    private function options(mixed $raw, Source $source, string $path, Errors $errors): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            $errors->add($path, 'invalid_value', ['field' => $path]);

            return [];
        }

        $declared = $source->options();
        $options = [];

        foreach ($raw as $i => $name) {
            if (! is_string($name) || ! isset($declared[$name])) {
                $errors->add("{$path}.{$i}", 'unknown_option', ['option' => is_scalar($name) ? (string) $name : '?', 'source' => $source->name()]);

                continue;
            }

            $options[] = $name;
        }

        return array_values(array_unique($options));
    }

    /**
     * Named inputs: every name must be declared by the source and the value
     * must fit the declared type the way an `eq` value would.
     *
     * @return array<string, mixed>
     */
    private function params(mixed $raw, Source $source, string $path, Errors $errors): array
    {
        if (! is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            $errors->add($path, 'invalid_value', ['field' => $path]);

            return [];
        }

        $declared = $source->params();
        $params = [];

        foreach ($raw as $name => $value) {
            $name = (string) $name;
            $param = $declared[$name] ?? null;

            if ($param === null) {
                $errors->add("{$path}.{$name}", 'unknown_param', ['param' => $name, 'source' => $source->name()]);

                continue;
            }

            if (! $this->valueFits(Op::Eq, $param, $value)) {
                $errors->add("{$path}.{$name}", 'invalid_value', ['field' => "{$path}.{$name}"]);

                continue;
            }

            $params[$name] = $param->type === Type::User ? $this->resolveMe($value, "{$path}.{$name}", $errors) : $value;
        }

        return $params;
    }

    /** The column behind an id as this request's user sees it; hidden columns are unknown to them. */
    private function column(mixed $id, Source $source): ?Column
    {
        $column = is_string($id) ? ($this->columnMap($source)[$id] ?? null) : null;

        return $column !== null && $column->visible($this->context) ? $column : null;
    }

    /** A string id is a column that no longer exists for this request; anything else is a malformed query. */
    private function skipOrReject(mixed $id, string $path, string $errorPath, Source $source, Errors $errors): void
    {
        if (is_string($id)) {
            $this->ignored[] = $path;

            return;
        }

        $errors->add($errorPath, 'unknown_column', ['column' => is_scalar($id) ? (string) $id : '?', 'source' => $source->name()]);
    }

    /** @return array<string, Column> */
    private function columnMap(Source $source): array
    {
        return $this->columnMaps[$source::class] ??= $source->columnMap($this->context);
    }

    private function limit(string $key): int
    {
        $value = $this->limits[$key] ?? null;

        return is_int($value) ? $value : PHP_INT_MAX;
    }
}
