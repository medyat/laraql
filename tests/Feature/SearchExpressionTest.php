<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Medyat\Laraql\Column\Column;
use Medyat\Laraql\Context;
use Medyat\Laraql\Source\Registry;
use Medyat\Laraql\Source\Source;
use Workbench\App\Models\User;
use Workbench\App\Models\Warehouse;

/**
 * A searchable expression column moves the whole search to the outer query, which only sees what
 * the sub-query selected: every searchable column must be selected, whatever `fields` asks for.
 */
final class SearchExpressionWarehouseSource extends Source
{
    public function name(): string
    {
        return 'search_expression_warehouse';
    }

    public function model(): string
    {
        return Warehouse::class;
    }

    public function columns(Context $context): array
    {
        return [
            Column::id('code')->searchable(),
            Column::string('name'),
            Column::string('code_lower')->expression('lower(warehouses.code)')->searchable(),
        ];
    }
}

beforeEach(function (): void {
    app(Registry::class)->register(SearchExpressionWarehouseSource::class);
    Warehouse::create(['id' => (string) Str::uuid(), 'code' => 'WH-1', 'name' => 'Main', 'is_active' => true]);
    Warehouse::create(['id' => (string) Str::uuid(), 'code' => 'ST-2', 'name' => 'Store', 'is_active' => true]);
});

it('searches whatever fields asks for', function (array $fields): void {
    $this->actingAs(new User(['name' => 'admin']))->postJson('/laraql', ['queries' => ['q' => [
        'source' => 'search_expression_warehouse',
        'fields' => $fields,
        'search' => 'wh',
    ]]])
        ->assertOk()
        ->assertJsonCount(1, 'results.q.rows')
        ->assertJsonMissingPath('results.q.rows.0.code_lower');
})->with([
    'no searchable column requested' => [['name']],
    'only the real one requested' => [['code']],
]);

it('counts with a search', function (): void {
    $this->actingAs(new User(['name' => 'admin']))->postJson('/laraql', ['queries' => ['q' => [
        'source' => 'search_expression_warehouse',
        'kind' => 'count',
        'search' => 'wh',
    ]]])->assertOk()->assertJsonPath('results.q.total', 1);
});
