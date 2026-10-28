<?php

declare(strict_types=1);

namespace Medyat\Laraql\Result;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Medyat\Laraql\Column\Column;
use Medyat\Laraql\Column\Type;
use Medyat\Laraql\Input;
use Medyat\Laraql\Support\Raw;

/**
 * Turns a hydrated model into a protocol row: values rendered by column
 * type, dotted ids nested, decorators applied. The key column always comes
 * first.
 */
final class RowFormatter
{
    /**
     * @param  list<Column>  $columns  Output columns, key first.
     * @param  list<Closure(Model, array<string, mixed>, Input): array<string, mixed>>  $decorators
     * @return array<string, mixed>
     */
    public function format(Model $model, array $columns, array $decorators, Input $input): array
    {
        /** @var array<string, mixed> $row */
        $row = [];

        foreach ($columns as $column) {
            $row = $this->set($row, explode('.', $column->id), $this->value($column, $model->getAttribute($column->alias())));
        }

        foreach ($decorators as $decorator) {
            $row = $decorator($model, $row, $input);
        }

        return $row;
    }

    /**
     * Sets a dotted path: `['company', 'title']` → `$row['company']['title']`.
     *
     * @param  array<string, mixed>  $row
     * @param  non-empty-list<string>  $path
     * @return array<string, mixed>
     */
    private function set(array $row, array $path, mixed $value): array
    {
        $head = array_shift($path);

        if ($path === []) {
            $row[$head] = $value;

            return $row;
        }

        $row[$head] = $this->set(Raw::assoc($row[$head] ?? []), $path, $value);

        return $row;
    }

    private function value(Column $column, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($column->type) {
            Type::Int => is_numeric($value) ? (int) $value : $value,
            Type::Float, Type::Money, Type::Percentage => is_numeric($value) ? (float) $value : $value,
            Type::Bool => (bool) $value,
            Type::Date => $value instanceof DateTimeInterface ? $value->format('Y-m-d') : substr(Raw::string($value), 0, 10),
            Type::Datetime => $this->datetime($value),
            Type::Multiselect, Type::Json => is_string($value) ? json_decode($value, true) : $value,
            default => $value,
        };
    }

    private function datetime(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc()->format('Y-m-d\TH:i:s\Z');
        }

        $text = Raw::string($value);

        return $text === '' ? null : CarbonImmutable::parse($text, 'UTC')->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
