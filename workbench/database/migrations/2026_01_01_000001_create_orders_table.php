<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('customer');
            $table->string('status');
            $table->decimal('gross_total', 12, 2);
            $table->date('due_date')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->text('note')->nullable();
            $table->json('labels')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
