<?php

declare(strict_types=1);

namespace Medyat\Laraql\Column;

use Closure;
use LogicException;
use Medyat\Laraql\Context;

/**
 * A typed, whitelisted column of a source. `id` is what clients see; `from`
 * is the database column (defaults to the last dotted segment of the id) or
 * `expression` a raw SQL expression. Dotted ids are nested in result rows.
 */
final class Column
{
    public private(set) string $from;

    public private(set) ?string $expression = null;

    /** Key into `Source::joins()` this column needs; inferred from a dotted `from`. */
    public private(set) ?string $join = null;

    public private(set) bool $filterable = true;

    public private(set) bool $sortable;

    public private(set) bool $searchable = false;

    /** Read on every rows query even when `fields` leaves it out; returned only when requested. */
    public private(set) bool $always = false;

    /** @var Closure(Context): bool|null */
    private ?Closure $visibleWhen = null;

    public private(set) ?string $currency = null;

    /** @var list<string|int>|null */
    public private(set) ?array $options = null;

    /** Human text for clients and agents (what the column means, units, caveats). */
    public private(set) ?string $description = null;

    private function __construct(public readonly string $id, public readonly Type $type)
    {
        $dot = strrpos($id, '.');
        $this->from = $dot === false ? $id : substr($id, $dot + 1);
        $this->sortable = $type->sortable();
    }

    public static function int(string $id): self
    {
        return new self($id, Type::Int);
    }

    public static function float(string $id): self
    {
        return new self($id, Type::Float);
    }

    /** @param  string|null  $currency  ISO code, or the id of the column holding it. */
    public static function money(string $id, ?string $currency = null): self
    {
        $column = new self($id, Type::Money);
        $column->currency = $currency;

        return $column;
    }

    public static function percentage(string $id): self
    {
        return new self($id, Type::Percentage);
    }

    public static function bool(string $id): self
    {
        return new self($id, Type::Bool);
    }

    public static function string(string $id): self
    {
        return new self($id, Type::String);
    }

    public static function text(string $id): self
    {
        return new self($id, Type::Text);
    }

    public static function id(string $id): self
    {
        return new self($id, Type::Id);
    }

    public static function email(string $id): self
    {
        return new self($id, Type::Email);
    }

    public static function url(string $id): self
    {
        return new self($id, Type::Url);
    }

    public static function phone(string $id): self
    {
        return new self($id, Type::Phone);
    }

    public static function color(string $id): self
    {
        return new self($id, Type::Color);
    }

    /** @param  list<string|int>  $options */
    public static function select(string $id, array $options): self
    {
        $column = new self($id, Type::Select);
        $column->options = $options;

        return $column;
    }

    /** @param  list<string|int>  $options */
    public static function multiselect(string $id, array $options): self
    {
        $column = new self($id, Type::Multiselect);
        $column->options = $options;

        return $column;
    }

    public static function user(string $id): self
    {
        return new self($id, Type::User);
    }

    public static function date(string $id): self
    {
        return new self($id, Type::Date);
    }

    public static function datetime(string $id): self
    {
        return new self($id, Type::Datetime);
    }

    public static function json(string $id): self
    {
        return new self($id, Type::Json);
    }

    /** Database column to read; `table.column` reads through the join named `table` (see `Source::joins()`). */
    public function from(string $column): self
    {
        $this->from = $column;

        $dot = strpos($column, '.');

        if ($dot !== false) {
            $this->join ??= substr($column, 0, $dot);
        }

        return $this;
    }

    /** Join (key into `Source::joins()`) an expression column depends on. */
    public function join(string $join): self
    {
        $this->join = $join;

        return $this;
    }

    /** Raw SQL expression computed in the sub-query (may reference the base table). */
    public function expression(string $sql): self
    {
        $this->expression = $sql;

        return $this;
    }

    public function searchable(): self
    {
        if (! $this->type->isText()) {
            throw new LogicException("Column [{$this->id}] of type [{$this->type->value}] cannot be searchable; only text types can.");
        }

        $this->searchable = true;

        return $this;
    }

    public function describe(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /** Select on every rows query so decorators and load options can rely on it, whatever `fields` says. */
    public function always(): self
    {
        $this->always = true;

        return $this;
    }

    /**
     * Show the column only when the predicate holds for the request; to other
     * users it does not exist (treated as an unknown column, absent from omitted
     * `fields`, skipped by search).
     *
     * @param  Closure(Context): bool  $when
     */
    public function visibleWhen(Closure $when): self
    {
        $this->visibleWhen = $when;

        return $this;
    }

    public function visible(Context $context): bool
    {
        return $this->visibleWhen === null || ($this->visibleWhen)($context);
    }

    public function notFilterable(): self
    {
        $this->filterable = false;

        return $this;
    }

    public function notSortable(): self
    {
        $this->sortable = false;

        return $this;
    }

    public function isExpression(): bool
    {
        return $this->expression !== null;
    }

    /** Filters and search on a real column (base table or joined) run inside the sub-query. */
    public function pushable(): bool
    {
        return $this->expression === null;
    }

    /** Qualified SQL column for the sub-query. */
    public function qualified(string $table): string
    {
        return str_contains($this->from, '.') ? $this->from : "{$table}.{$this->from}";
    }

    /** SQL alias used inside the query; dots are not safe in identifiers. */
    public function alias(): string
    {
        return str_replace('.', '__', $this->id);
    }
}
