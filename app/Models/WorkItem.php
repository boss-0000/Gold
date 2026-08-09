<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkItem extends Model
{
    protected $fillable = [
        'scenario', 'intent_key', 'reference', 'store_key', 'player_id',
        'package_id', 'processed', 'outcome',
    ];

    protected function casts(): array
    {
        return ['processed' => 'boolean'];
    }
}
