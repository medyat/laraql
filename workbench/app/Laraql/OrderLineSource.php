<?php

declare(strict_types=1);

namespace Workbench\App\Laraql;

use Illuminate\Database\Eloquent\Builder;
use Medyat\Laraql\Column\Column;
use Medyat\Laraql\Context;
use Medyat\Laraql\Input;
use Medyat\Laraql\Source\Source;
use Workbench\App\Models\OrderLine;

/** Mirrors fixtures/v1/dataset.json · sources.order_line */
final class OrderLineSource extends Source
{
    public function name(): string
    {
        return 'order_line';
    }

    public function model(): string
    {
        return OrderLine::class;
    }

    public function columns(Context $context): array
    {
        return [
            Column::int('id'),
            Column::int('order_id'),
            Column::id('product_code')->searchable(),
            Column::int('qty'),
            Column::int('position'),
            Column::id('order.code')->from('orders.code')->searchable(),
        ];
    }

    public function defaultSort(): array
    {
        return ['position' => 'asc'];
    }

    public function joins(Input $input): array
    {
        return ['orders' => ['orders.id', '=', 'order_lines.order_id']];
    }

    /** scope() filters on orders.code, so the join must be there even when no order.* column is used. */
    public function alwaysJoin(): array
    {
        return ['orders'];
    }

    /** Lines whose order is gone are hidden (result-neutral on the dataset). */
    public function scope(Builder $query, Input $input): void
    {
        $query->whereNotNull('orders.code');
    }
}
