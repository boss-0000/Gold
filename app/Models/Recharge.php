<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Recharge extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'reference', 'idempotency_key', 'intent_key', 'store_key', 'player_id', 'package_id',
        'amount_cost', 'amount_price', 'status', 'provider_ref', 'provider_tx_id',
        'attempts', 'error',
    ];

    protected function casts(): array
    {
        return [
            'amount_cost' => 'decimal:2',
            'amount_price' => 'decimal:2',
            'attempts' => 'integer',
        ];
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }
}
