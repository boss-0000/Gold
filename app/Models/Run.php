<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Run extends Model
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'scenario', 'status', 'workers', 'result_naive', 'result_safe',
        'error', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'result_naive' => 'array',
            'result_safe' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
