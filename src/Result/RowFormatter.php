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
            Type::Float, Type::Percentage => is_numeric($value) ? (float) $value : $value,
            Type::Money => $this->money($value, $column->scale),
            Type::Bool => (bool) $value,
            Type::Date => $value instanceof DateTimeInterface ? $value->format('Y-m-d') : substr(Raw::string($value), 0, 10),
            Type::Datetime => $this->datetime($value),
            Type::Multiselect, Type::Json => is_string($value) ? json_decode($value, true) : $value,
            default => $value,
        };
    }

    /** Decimal string with exactly `$scale` digits; exact database strings are rounded half up as text, never through a float. */
    private function money(mixed $value, int $scale): mixed
    {
        if (is_int($value) || is_float($value)) {
            return number_format($value, $scale, '.', '');
        }

        if (! is_string($value) || preg_match('/^(-?)(\d+)(?:\.(\d*))?$/', $value, $m) !== 1) {
            return $value;
        }

        $fraction = str_pad($m[3] ?? '', $scale + 1, '0');
        $digits = $m[2].substr($fraction, 0, $scale);

        if ($fraction[$scale] >= '5') {
            for ($i = strlen($digits) - 1; $i >= 0 && $digits[$i] === '9'; $i--) {
                $digits[$i] = '0';
            }
            $digits = $i < 0 ? '1'.$digits : substr_replace($digits, (string) ((int) $digits[$i] + 1), $i, 1);
        }

        $digits = str_pad(ltrim($digits, '0'), $scale + 1, '0', STR_PAD_LEFT);
        $sign = $m[1] !== '' && trim($digits, '0') !== '' ? '-' : '';

        return $scale === 0 ? $sign.$digits : $sign.substr($digits, 0, -$scale).'.'.substr($digits, -$scale);
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
