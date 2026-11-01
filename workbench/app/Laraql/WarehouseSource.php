<?php

declare(strict_types=1);

namespace Workbench\App\Laraql;

use Medyat\Laraql\Column\Column;
use Medyat\Laraql\Context;
use Medyat\Laraql\Source\Source;
use Workbench\App\Models\Warehouse;

/** Mirrors fixtures/v1/dataset.json · sources.warehouse; the key column is not declared on purpose. */
final class WarehouseSource extends Source
{
    public function name(): string
    {
        return 'warehouse';
    }

    public function model(): string
    {
        return Warehouse::class;
    }

    public function columns(Context $context): array
    {
        return [
            Column::id('code')->searchable(),
            Column::string('name')->searchable(),
            Column::bool('is_active'),
        ];
    }
}
