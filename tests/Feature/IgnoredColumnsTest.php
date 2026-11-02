<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\DatabaseSeeder;

uses(RefreshDatabase::class);

/**
 * The fixture unknown-column-ignored pins fields, sort and filters; this covers
 * what it does not: include paths, count, and the cases that stay a 422.
 */
function queryOrders(array $query): array
{
    return test()->actingAs(new User(['name' => 'admin']))
        ->postJson('/laraql', ['queries' => ['q' => ['source' => 'order'] + $query]])
        ->assertOk()
        ->json('results.q');
}

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

it('reports skipped columns inside an include by their full path', function (): void {
    $result = queryOrders(['fields' => ['code'], 'include' => ['lines' => ['fields' => ['product_code', 'nope']]]]);

    expect($result['ignored'])->toBe(['include.lines.fields.1'])
        ->and($result['rows'][0]['lines'][0])->toHaveKey('product_code');
});

it('falls back to the default order when every sort key is skipped', function (): void {
    $result = queryOrders(['fields' => ['code'], 'sort' => [['column' => 'nope', 'dir' => 'desc']]]);

    expect(array_column($result['rows'], 'id'))->toBe(array_column(queryOrders(['fields' => ['code']])['rows'], 'id'))
        ->and($result['ignored'])->toBe(['sort.0']);
});

it('reports skipped columns on a count', function (): void {
    expect(queryOrders(['kind' => 'count', 'fields' => ['nope']]))->toMatchArray(['ignored' => ['fields.0']]);
});

it('leaves ignored out when nothing was skipped', function (): void {
    expect(queryOrders(['fields' => ['code']]))->not->toHaveKey('ignored');
});

it('still rejects an id that is not a string', function (): void {
    expect(queryOrders(['fields' => [1]])['error']['errors'])->toHaveKey('fields.0');
});

it('still rejects a sort on a column that exists but is not sortable', function (): void {
    expect(queryOrders(['sort' => [['column' => 'labels', 'dir' => 'asc']]])['error']['errors'])->toHaveKey('sort.0.column');
});
