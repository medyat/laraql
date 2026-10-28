<?php

declare(strict_types=1);

namespace Workbench\App\Laraql;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Medyat\Laraql\Column\Column;
use Medyat\Laraql\Context;
use Medyat\Laraql\Input;
use Medyat\Laraql\Source\Option;
use Medyat\Laraql\Source\Relation;
use Medyat\Laraql\Source\Source;
use Workbench\App\Models\Order;
use Workbench\App\Models\OrderLine;
use Workbench\App\Models\User;

/** Mirrors fixtures/v1/dataset.json · sources.order */
final class OrderSource extends Source
{
    public function name(): string
    {
        return 'order';
    }

    public function model(): string
    {
        return Order::class;
    }

    public function describe(): ?string
    {
        return 'Sales orders. Query here for order headers; use the lines relation or the order_line source for products on an order.';
    }

    public function columns(): array
    {
        return [
            Column::int('id'),
            Column::id('code')->searchable()->describe('Order number (SO-…), unique.'),
            Column::string('customer')->searchable()->describe('Customer name as printed on the order.'),
            Column::select('status', ['draft', 'released', 'done'])->always()->describe('Lifecycle state.'), // with_ability reads it
            Column::money('gross_total', 'TRY')->describe('Total including VAT.'),
            Column::date('due_date')->describe('Promised delivery date; empty when not agreed yet.'),
            Column::datetime('created_at'),
            Column::text('note')
                ->visibleWhen(fn (Context $context): bool => $context->user instanceof User && $context->user->name === 'admin')
                ->describe('Internal note, staff (admin) only.'),
            Column::multiselect('labels', ['export', 'vip', 'rush'])->describe('Order labels; a row may carry several.'),
            Column::user('owner_id')->describe('Owner user id; "@me" resolves to the current user.'),
            Column::int('lines_count')
                ->expression('(select count(*) from order_lines where order_lines.order_id = orders.id)')
                ->notFilterable()
                ->describe('Number of lines on the order.'),
            Column::int('lines_qty')
                ->from('line_totals.qty')
                ->describe('Total quantity on the order; with params.product_code, only that product\'s quantity. Empty when no line matches.'),
        ];
    }

    public function params(): array
    {
        return ['product_code' => Column::id('product_code')->describe('Narrow lines_qty to one product code.')];
    }

    public function joins(Input $input): array
    {
        $code = $input->params['product_code'] ?? null;
        $totals = OrderLine::query()
            ->selectRaw('order_id, sum(qty) as qty')
            ->when($code !== null, fn (Builder $query) => $query->where('product_code', $code))
            ->groupBy('order_id');

        return ['line_totals' => fn (Builder $query) => $query->leftJoinSub($totals, 'line_totals', 'line_totals.order_id', '=', 'orders.id')];
    }

    public function relations(): array
    {
        return ['lines' => Relation::hasMany(OrderLineSource::class, 'order_id', description: 'Products on the order, in position order.')];
    }

    public function options(): array
    {
        return [
            'only_done' => Option::scope(fn (Builder $query) => $query->where('status', 'done'), 'Only orders whose status is done.'),
            'with_lines' => Option::load('lines', 'Eager-loads the lines so decorators can read them without extra queries.'),
            'with_bulk_lines' => Option::load(['lines' => fn (HasMany $lines) => $lines->where('qty', '>=', 2)], 'Eager-loads only the lines with qty >= 2 (a constrained load); product_codes then lists those.'),
            'product_codes' => Option::decorate(
                fn (Order $order, array $row): array => $row + ['product_codes' => $order->lines->pluck('product_code')->all()],
                'Adds product_codes: the product codes on the order, in position order.',
            ),
            'with_ability' => Option::decorate(
                fn (Order $order, array $row): array => $row + ['ability' => ['can_edit' => $order->status !== 'done']],
                'Adds ability.can_edit: orders can be edited until they are done.',
            ),
        ];
    }
}
