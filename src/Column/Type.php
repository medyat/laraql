<?php

declare(strict_types=1);

namespace Medyat\Laraql\Column;

use Medyat\Laraql\Filter\Op;

/**
 * Column types. The type decides which operators a column accepts, how a
 * filter value is applied and how a database value is rendered in a row.
 */
enum Type: string
{
    case Int = 'int';
    case Float = 'float';
    case Money = 'money';
    case Percentage = 'percentage';
    case Bool = 'bool';
    case String = 'string';
    case Text = 'text';
    case Id = 'id';
    case Email = 'email';
    case Url = 'url';
    case Phone = 'phone';
    case Color = 'color';
    case Select = 'select';
    case Multiselect = 'multiselect';
    case User = 'user';
    case Date = 'date';
    case Datetime = 'datetime';
    case Json = 'json';

    /**
     * @return list<Op>
     */
    public function operators(): array
    {
        $empty = [Op::IsEmpty, Op::IsNotEmpty];

        return match ($this) {
            self::Int => [Op::Eq, Op::Neq, Op::Gt, Op::Gte, Op::Lt, Op::Lte, Op::Between, Op::In, Op::NotIn, ...$empty],
            // No in/not_in on decimals: exact equality on them is fragile.
            self::Float, self::Money, self::Percentage => [Op::Eq, Op::Neq, Op::Gt, Op::Gte, Op::Lt, Op::Lte, Op::Between, ...$empty],
            self::Bool => [Op::Eq, ...$empty],
            self::String, self::Email, self::Url, self::Phone => [Op::Contains, Op::NotContains, Op::Eq, Op::Neq, Op::StartsWith, Op::EndsWith, Op::In, Op::NotIn, ...$empty],
            self::Text => [Op::Contains, Op::NotContains, Op::Eq, Op::Neq, Op::StartsWith, Op::EndsWith, ...$empty],
            self::Id => [Op::In, Op::NotIn, Op::Contains, Op::NotContains, Op::Eq, Op::Neq, Op::StartsWith, Op::EndsWith, ...$empty],
            self::Color, self::Select, self::User => [Op::Eq, Op::Neq, Op::In, Op::NotIn, ...$empty],
            self::Multiselect => [Op::In, Op::NotIn, ...$empty],
            self::Date, self::Datetime => [Op::Eq, Op::Before, Op::After, Op::Within, ...$empty],
            self::Json => $empty,
        };
    }

    public function allows(Op $op): bool
    {
        return in_array($op, $this->operators(), true);
    }

    public function isNumeric(): bool
    {
        return in_array($this, [self::Int, self::Float, self::Money, self::Percentage], true);
    }

    public function isText(): bool
    {
        return in_array($this, [self::String, self::Text, self::Id, self::Email, self::Url, self::Phone], true);
    }

    public function isList(): bool
    {
        return $this === self::Multiselect;
    }

    public function isDate(): bool
    {
        return in_array($this, [self::Date, self::Datetime], true);
    }

    public function sortable(): bool
    {
        return ! $this->isList() && $this !== self::Json;
    }
}
