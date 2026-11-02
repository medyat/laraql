<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Medyat\Laraql\Column\Column;
use Medyat\Laraql\Context;
use Medyat\Laraql\Exceptions\InvalidQueryException;
use Medyat\Laraql\Input;
use Medyat\Laraql\Query\Executor;
use Medyat\Laraql\Query\Validator;
use Medyat\Laraql\Source\Source;
use Workbench\App\Models\Order;
use Workbench\App\Models\OrderLine;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\DatabaseSeeder;

uses(RefreshDatabase::class);

/**
 * Source hooks see the request through one Input: the user, the validated
 * params and the requested field ids. The fixtures cover the protocol side
 * (params-join, error-params); this checks what the hooks receive.
 */
function probeSource(): Source
{
    return new class extends Source
    {
        /** @var array<string, mixed> */
        public static array $seen = [];

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
            return [Column::int('id'), Column::id('code'), Column::int('lines_qty')->from('t.qty')];
        }

        public function params(): array
        {
            return ['min_qty' => Column::int('min_qty')];
        }

        public function scope(Builder $query, Input $input): void
        {
            self::$seen['scope'] = [$input->user?->getAttribute('name'), $input->params, $input->fields];
        }

        public function joins(Input $input): array
        {
            $lines = OrderLine::query()->selectRaw('order_id, sum(qty) as qty')->where('qty', '>=', $input->params['min_qty'])->groupBy('order_id');

            return ['t' => fn (Builder $query) => $query->leftJoinSub($lines, 't', 't.order_id', '=', 'orders.id')];
        }

        public function decorators(): array
        {
            return [fn (Order $order, array $row, Input $input): array => $row + ['min' => $input->params['min_qty']]];
        }
    };
}

it('hands scope, joins and decorators the user, params and requested fields', function (): void {
    $this->seed(DatabaseSeeder::class);
    $source = probeSource();

    $context = new Context('UTC', CarbonImmutable::now(), new User(['name' => 'ayse']));
    $query = app(Validator::class)->validate([
        'source' => 'probe',
        'fields' => ['code', 'lines_qty'],
        'params' => ['min_qty' => 4],
        'filters' => [['column' => 'code', 'op' => 'eq', 'value' => 'SO-1001']],
    ], $source, $context);

    $result = app(Executor::class)->rows($query, $context);

    expect($source::$seen['scope'])->toBe(['ayse', ['min_qty' => 4], ['id', 'code', 'lines_qty']]);
    expect($result['rows'])->toBe([['id' => 1, 'code' => 'SO-1001', 'lines_qty' => 10, 'min' => 4]]);
});

it('rejects a param value that does not fit the declared type under its path', function (): void {
    $context = new Context('UTC', CarbonImmutable::now());

    try {
        app(Validator::class)->validate(['source' => 'probe', 'params' => ['min_qty' => [4]]], probeSource(), $context);
    } catch (InvalidQueryException $e) {
        expect($e->toArray()['errors'])->toBe(['params.min_qty' => ['Invalid value for "params.min_qty".']]);

        return;
    }

    $this->fail('No exception thrown.');
});
