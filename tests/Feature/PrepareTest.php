<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Medyat\Laraql\Column\Column;
use Medyat\Laraql\Context;
use Medyat\Laraql\Input;
use Medyat\Laraql\Query\Executor;
use Medyat\Laraql\Query\Validator;
use Medyat\Laraql\Source\Source;
use Workbench\App\Models\Order;
use Workbench\Database\Seeders\DatabaseSeeder;

uses(RefreshDatabase::class);

/** `prepare()` sees the whole page once, so a decorator reads a relation loaded in bulk instead of querying per row. */
function prepareSource(): Source
{
    return new class extends Source
    {
        /** @var list<int> */
        public static array $calls = [];

        public function name(): string
        {
            return 'prepared';
        }

        public function model(): string
        {
            return Order::class;
        }

        public function columns(Context $context): array
        {
            return [Column::int('id'), Column::id('code')];
        }

        public function prepare(Collection $models, Input $input): void
        {
            self::$calls[] = $models->count();
            $models->load('lines');
        }

        public function decorators(): array
        {
            return [fn (Order $order, array $row): array => $row + ['lines_loaded' => $order->relationLoaded('lines')]];
        }
    };
}

it('prepares the page once, before the decorators', function (): void {
    $this->seed(DatabaseSeeder::class);
    $source = prepareSource();
    $context = new Context('UTC', CarbonImmutable::now());
    $query = app(Validator::class)->validate(['source' => 'prepared', 'fields' => ['code'], 'page' => ['size' => 2]], $source, $context);

    $result = app(Executor::class)->rows($query, $context);

    expect($source::$calls)->toBe([2])
        ->and(array_column($result['rows'], 'lines_loaded'))->toBe([true, true]);
});
