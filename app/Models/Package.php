<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    protected $fillable = ['code', 'name', 'cost', 'price'];

    protected function casts(): array
    {
        return ['cost' => 'decimal:2', 'price' => 'decimal:2'];
    }
}
