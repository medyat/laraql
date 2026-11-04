<?php

declare(strict_types=1);

namespace Medyat\Laraql\Query\Compiler;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Medyat\Laraql\Column\Column;
use Medyat\Laraql\Column\Type;
use Medyat\Laraql\Filter\Op;
use Medyat\Laraql\Query\Condition;
use Medyat\Laraql\Support\Raw;
use Medyat\Laraql\Support\Sql;

/**
 * Applies one condition to a builder. `$target` is the SQL identifier to
 * compare: the real column inside the sub-query or the alias on the outer
 * query; the strategy only depends on the column type and operator.
 */
final readonly class FilterCompiler
{
    /** @param  Builder<Model>  $query */
    public function apply(Builder $query, string $target, Condition $condition, DateRange $dates): void
    {
        $column = $condition->column;
        $type = $column->type;

        match ($condition->op) {
            Op::IsEmpty => $this->empty($query, $target, $type, false),
            Op::IsNotEmpty => $this->empty($query, $target, $type, true),
            Op::Eq => $type->isDate() ? $this->day($query, $target, $type, $dates->dayOf($condition->value), $dates) : $query->where($target, '=', $this->scalar($type, $condition->value)),
            Op::Neq => $query->where($target, '!=', $this->scalar($type, $condition->value)),
            Op::Gt => $query->where($target, '>', $condition->value),
            Op::Gte => $query->where($target, '>=', $condition->value),
            Op::Lt => $query->where($target, '<', $condition->value),
            Op::Lte => $query->where($target, '<=', $condition->value),
            Op::Between => $this->between($query, $target, $condition->value),
            Op::Contains => $this->like($query, $target, '%'.$this->escape($condition->value).'%', false),
            Op::NotContains => $this->like($query, $target, '%'.$this->escape($condition->value).'%', true),
            Op::StartsWith => $this->like($query, $target, $this->escape($condition->value).'%', false),
            Op::EndsWith => $this->like($query, $target, '%'.$this->escape($condition->value), false),
            Op::In => $this->in($query, $target, $type, $condition->value, false),
            Op::NotIn => $this->in($query, $target, $type, $condition->value, true),
            Op::Before => $this->before($query, $target, $type, $dates->dayOf($condition->value), $dates),
            Op::After => $this->after($query, $target, $type, $dates->dayOf($condition->value), $dates),
            Op::Within => $this->within($query, $target, $type, $condition->value, $dates),
        };
    }

    /**
     * Search: every token must appear in at least one searchable column.
     *
     * @param  Builder<Model>  $query
     * @param  list<string>  $targets
     * @param  list<string>  $tokens
     */
    public function search(Builder $query, array $targets, array $tokens): void
    {
        foreach ($tokens as $token) {
            $query->where(function (Builder $group) use ($targets, $token): void {
                foreach ($targets as $target) {
                    $group->whereRaw($this->likeSql($group, $target, false), ['%'.$this->escape($token).'%'], 'or');
                }
            });
        }
    }

    private function scalar(Type $type, mixed $value): mixed
    {
        return $type === Type::Bool ? (bool) $value : $value;
    }

    /** @param  Builder<Model>  $query */
    private function empty(Builder $query, string $target, Type $type, bool $not): void
    {
        $query->where(function (Builder $group) use ($target, $type, $not): void {
            if ($not) {
                $group->whereNotNull($target);

                if ($type->isText()) {
                    $group->where($target, '!=', '');
                } elseif ($type->isList()) {
                    $group->whereJsonLength($target, '>', 0);
                }

                return;
            }

            $group->whereNull($target);

            if ($type->isText()) {
                $group->orWhere($target, '=', '');
            } elseif ($type->isList()) {
                $group->orWhereJsonLength($target, 0);
            }
        });
    }

    /** @param  Builder<Model>  $query */
    private function between(Builder $query, string $target, mixed $value): void
    {
        [$min, $max] = is_array($value) ? $value : [null, null];

        if ($min !== null && $max !== null) {
            $query->whereBetween($target, [$min, $max]);
        } elseif ($min !== null) {
            $query->where($target, '>=', $min);
        } elseif ($max !== null) {
            $query->where($target, '<=', $max);
        }
    }

    /** @param  Builder<Model>  $query */
    private function like(Builder $query, string $target, string $pattern, bool $not): void
    {
        $query->whereRaw($this->likeSql($query, $target, $not), [$pattern]);
    }

    /**
     * Portable case-insensitive LIKE: standard SQL only (LOWER, LIKE, ESCAPE),
     * identifier wrapped by the grammar, value bound. Both sides go through the
     * database's LOWER: PHP lowercases some letters differently ("İ" becomes
     * "i" plus a combining dot), and the value would then never match. `!` is the escape
     * character because a backslash inside a string literal is itself an
     * escape on MySQL, and SQLite has no default escape at all.
     *
     * @param  Builder<Model>  $query
     */
    private function likeSql(Builder $query, string $target, bool $not): Expression
    {
        $wrapped = $query->getQuery()->getGrammar()->wrap($target);

        return Sql::raw('LOWER('.$wrapped.') '.($not ? 'NOT LIKE' : 'LIKE')." LOWER(?) ESCAPE '!'");
    }

    /** User text is never a pattern: `%`, `_` and the escape character itself become literals. */
    private function escape(mixed $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Raw::string($value));
    }

    /** @param  Builder<Model>  $query */
    private function in(Builder $query, string $target, Type $type, mixed $values, bool $not): void
    {
        $values = is_array($values) ? array_values($values) : [];

        if (! $type->isList()) {
            $not ? $query->whereNotIn($target, $values) : $query->whereIn($target, $values);

            return;
        }

        $anyOf = function (Builder $group) use ($target, $values): void {
            foreach ($values as $value) {
                $group->orWhereJsonContains($target, $value);
            }
        };

        $not ? $query->whereNot($anyOf) : $query->where($anyOf);
    }

    /** @param  Builder<Model>  $query */
    private function day(Builder $query, string $target, Type $type, string $day, DateRange $dates): void
    {
        if ($type === Type::Date) {
            $query->where($target, '=', $day);

            return;
        }

        $query->where($target, '>=', $dates->startUtc($day))->where($target, '<', $dates->endUtcExclusive($day));
    }

    /** @param  Builder<Model>  $query */
    private function before(Builder $query, string $target, Type $type, string $day, DateRange $dates): void
    {
        $type === Type::Date
            ? $query->where($target, '<', $day)
            : $query->where($target, '<', $dates->startUtc($day));
    }

    /** @param  Builder<Model>  $query */
    private function after(Builder $query, string $target, Type $type, string $day, DateRange $dates): void
    {
        $type === Type::Date
            ? $query->where($target, '>', $day)
            : $query->where($target, '>=', $dates->endUtcExclusive($day));
    }

    /** @param  Builder<Model>  $query */
    private function within(Builder $query, string $target, Type $type, mixed $value, DateRange $dates): void
    {
        $range = is_array($value) ? $value : [];
        [$from, $to] = isset($range['preset']) && is_string($range['preset'])
            ? $dates->preset($range['preset'])
            : [substr(Raw::string($range['from'] ?? ''), 0, 10), substr(Raw::string($range['to'] ?? ''), 0, 10)];

        if ($from !== null && $from !== '') {
            $type === Type::Date
                ? $query->where($target, '>=', $from)
                : $query->where($target, '>=', $dates->startUtc($from));
        }

        if ($to !== null && $to !== '') {
            $type === Type::Date
                ? $query->where($target, '<=', $to)
                : $query->where($target, '<', $dates->endUtcExclusive($to));
        }
    }
}
