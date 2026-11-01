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

/**
 * Sources are singletons in the registry and Octane reuses the process, so one
 * instance answers every tenant. The ambient tenant below stands in for an
 * application's tenant switch: the second tenant must not see the first one's
 * columns, and must see its own.
 */
final class AmbientTenant
{
    public static string $columnId = '';
}

function perRequestSource(): Source
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
            return [
                Column::int('id'),
                Column::id('code'),
                Column::string('variables.'.AmbientTenant::$columnId)->from('customer'),
            ];
        }
    };
}

function runPerRequest(Source $source, array $raw): array
{
    $context = new Context('UTC', CarbonImmutable::now());
    $query = app(Validator::class)->validate(['source' => 'probe'] + $raw, $source, $context);

    return app(Executor::class)->rows($query, $context)['rows'];
}

it('resolves columns per request on a shared source instance', function (): void {
    $this->seed(DatabaseSeeder::class);
    $source = perRequestSource();

    AmbientTenant::$columnId = 'first';
    expect(runPerRequest($source, ['fields' => ['variables.first']]))->not->toBe([]);

    AmbientTenant::$columnId = 'second';
    expect(runPerRequest($source, ['fields' => ['variables.second']]))->not->toBe([]);

    expect(fn () => runPerRequest($source, ['filters' => [['column' => 'variables.first', 'op' => 'is_empty']]]))->toThrow(InvalidQueryException::class);
});

it('leaves another tenant\'s columns out of an answer without fields', function (): void {
    $this->seed(DatabaseSeeder::class);
    $source = perRequestSource();

    AmbientTenant::$columnId = 'first';
    runPerRequest($source, []);

    AmbientTenant::$columnId = 'second';
    $row = runPerRequest($source, [])[0];

    expect($row['variables'])->toHaveKey('second')->not->toHaveKey('first');
});
