<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Medyat\Laraql\Column\Column;
use Medyat\Laraql\Context;
use Workbench\App\Models\User;

function guardContext(string $user): Context
{
    return new Context('UTC', CarbonImmutable::now(), new User(['name' => $user]));
}

beforeEach(function (): void {
    Column::guard('price', fn (Context $c): bool => $c->user instanceof User && $c->user->name === 'admin');
});

it('shows a guarded column only when its rule holds', function (): void {
    $column = Column::money('total')->guardedBy('price');

    expect($column->visible(guardContext('admin')))->toBeTrue()
        ->and($column->visible(guardContext('ayse')))->toBeFalse();
});

it('needs both the guard and visibleWhen', function (): void {
    $column = Column::money('total')->guardedBy('price')->visibleWhen(fn (Context $c): bool => false);

    expect($column->visible(guardContext('admin')))->toBeFalse();
});

it('throws on a guard nobody defined', function (): void {
    Column::money('total')->guardedBy('prise')->visible(guardContext('admin'));
})->throws(LogicException::class, 'Column [total] is guarded by [prise]');
