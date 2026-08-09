<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    protected $fillable = ['key', 'label', 'balance'];

    protected function casts(): array
    {
        return ['balance' => 'decimal:4'];
    }
}
