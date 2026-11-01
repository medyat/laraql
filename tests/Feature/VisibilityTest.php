<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Medyat\Laraql\Column\Column;
use Medyat\Laraql\Context;
use Medyat\Laraql\Input;
use Medyat\Laraql\Query\Executor;
use Medyat\Laraql\Query\Validator;
use Medyat\Laraql\Source\Source;
use Workbench\App\Models\Order;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\DatabaseSeeder;

uses(RefreshDatabase::class);

/**
 * The fixture visibility-hidden-column pins the 422s and the omitted-fields
 * answer; this covers the two paths a fixture cannot reach: search skips a
 * hidden searchable column, and a hidden always() column is still read for
 * decorators without being returned.
 */
function visibilitySource(): Source
{
    return new class extends Source
    {
        public function name(): string
        {
            return 'probe';
        }

        public function model(): string
        {
            return Order::class;
        }

        public function columns(Context $context): array
        {
            $admin = fn (Context $context): bool => $context->user instanceof User && $context->user->name === 'admin';

            return [
                Column::int('id'),
                Column::id('code')->searchable(),
                Column::string('customer')->searchable()->visibleWhen($admin),
                Column::select('status', ['draft', 'released', 'done'])->always()->visibleWhen($admin),
            ];
        }

        public function decorators(): array
        {
            return [fn (Order $order, array $row, Input $input): array => $row + ['done' => $order->status === 'done']];
        }
    };
}

function rowsFor(string $user, array $raw): array
{
    $context = new Context('UTC', CarbonImmutable::now(), new User(['name' => $user]));
    $query = app(Validator::class)->validate(['source' => 'probe'] + $raw, visibilitySource(), $context);

    return app(Executor::class)->rows($query, $context)['rows'];
}

it('skips hidden columns when searching', function (): void {
    $this->seed(DatabaseSeeder::class);

    expect(rowsFor('admin', ['fields' => ['code'], 'search' => 'ege']))->toHaveCount(2); // customer "Ege Tarim Makine"
    expect(rowsFor('ayse', ['fields' => ['code'], 'search' => 'ege']))->toBe([]);
});

it('still reads a hidden always() column for decorators without returning it', function (): void {
    $this->seed(DatabaseSeeder::class);

    $rows = rowsFor('ayse', ['filters' => [['column' => 'code', 'op' => 'eq', 'value' => 'SO-1003']]]);

    expect($rows)->toBe([['id' => 3, 'code' => 'SO-1003', 'done' => true]]);
});
