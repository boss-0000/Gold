<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LedgerEntry extends Model
{
    public const UPDATED_AT = null; // append-only: solo created_at

    protected $fillable = [
        'wallet_key', 'recharge_id', 'type', 'amount', 'balance_after', 'meta', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'balance_after' => 'decimal:4',
            'meta' => 'array',
        ];
    }
}
