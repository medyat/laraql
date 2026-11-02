<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Medyat\Laraql\Column\Column;
use Medyat\Laraql\Context;
use Medyat\Laraql\Input;
use Medyat\Laraql\Result\RowFormatter;

function money(mixed $value, int $scale = 2): mixed
{
    $model = new class extends Model {};
    $model->setRawAttributes(['amount' => $value]);

    $row = new RowFormatter()->format($model, [Column::money('amount')->scale($scale)], [], new Input(new Context('UTC', CarbonImmutable::now())));

    return $row['amount'];
}

it('answers money as a decimal string with the column scale', function (mixed $value, int $scale, ?string $expected): void {
    expect(money($value, $scale))->toBe($expected);
})->with([
    'database string' => ['4100.00', 2, '4100.00'],
    'padded' => ['300.5', 2, '300.50'],
    'integer string' => ['12', 2, '12.00'],
    'half up' => ['0.125', 2, '0.13'],
    'carry through nines' => ['9.995', 2, '10.00'],
    'negative' => ['-12.345', 2, '-12.35'],
    'negative zero' => ['-0.004', 2, '0.00'],
    'beyond float precision' => ['12345678901234567.895', 2, '12345678901234567.90'],
    'scale 0' => ['7.5', 0, '8'],
    'scale 4' => ['1.2', 4, '1.2000'],
    'sqlite float' => [300.5, 2, '300.50'],
    'sqlite int' => [4100, 2, '4100.00'],
    'null' => [null, 2, null],
]);

it('takes a scale only on money columns', function (): void {
    Column::int('qty')->scale(2);
})->throws(LogicException::class);
