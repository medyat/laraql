<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /** An app model that eager-loads by default; laraql must not pay for it (eager loads come only from load options). */
    protected $with = ['lines'];

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('position');
    }

    protected function casts(): array
    {
        return ['labels' => 'array', 'created_at' => 'datetime'];
    }
}
