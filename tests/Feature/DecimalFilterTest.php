<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Medyat\Laraql\Column\Column;
use Medyat\Laraql\Context;
use Medyat\Laraql\Exceptions\InvalidQueryException;
use Medyat\Laraql\Query\Executor;
use Medyat\Laraql\Query\Validator;
use Medyat\Laraql\Source\Source;
use Workbench\App\Models\Order;
use Workbench\Database\Seeders\DatabaseSeeder;

uses(RefreshDatabase::class);

// Ondalık bir kolon (para değil): değer metin gelir, süzgeç metni de sayıyı da alır.
function decimalSource(): Source
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
            return [Column::int('id'), Column::decimal('total')->from('gross_total')->scale(2)];
        }
    };
}

function decimalRows(array $raw): array
{
    $context = new Context('UTC', CarbonImmutable::now());
    $query = app(Validator::class)->validate(['source' => 'probe', 'fields' => ['total'], 'sort' => [['column' => 'id', 'dir' => 'asc']]] + $raw, decimalSource(), $context);

    return app(Executor::class)->rows($query, $context)['rows'];
}

it('filters a decimal column by a decimal string or a number and answers text', function (mixed $value): void {
    $this->seed(DatabaseSeeder::class);

    $rows = decimalRows(['filters' => [['column' => 'total', 'op' => 'gt', 'value' => $value]]]);

    expect(array_column($rows, 'total'))->toBe(['4100.00', '2200.00']);
})->with(['decimal string' => ['2000'], 'number' => [2000]]);

it('rejects a malformed decimal string', function (): void {
    $this->seed(DatabaseSeeder::class);

    decimalRows(['filters' => [['column' => 'total', 'op' => 'gt', 'value' => '2.000,5']]]);
})->throws(InvalidQueryException::class);
