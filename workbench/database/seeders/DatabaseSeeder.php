<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Seeds fixtures/v1/dataset.json so the fixtures replay against real tables. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $dataset = json_decode((string) file_get_contents(__DIR__.'/../../../fixtures/v1/dataset.json'), true, 512, JSON_THROW_ON_ERROR);

        foreach ($dataset['rows']['order'] as $order) {
            $order['labels'] = json_encode($order['labels']);
            $order['created_at'] = CarbonImmutable::parse($order['created_at'])->utc()->format('Y-m-d H:i:s');
            DB::table('orders')->insert($order);
        }

        DB::table('order_lines')->insert($dataset['rows']['order_line']);
        DB::table('warehouses')->insert($dataset['rows']['warehouse']);
    }
}
