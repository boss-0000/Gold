<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderCharge extends Model
{
    public const UPDATED_AT = null; // solo created_at

    protected $fillable = [
        'provider_ref', 'player_id', 'package_id', 'amount', 'provider_tx_id', 'created_at',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
