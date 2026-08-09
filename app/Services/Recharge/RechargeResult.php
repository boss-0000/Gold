<?php

declare(strict_types=1);

namespace App\Services\Recharge;

final readonly class RechargeResult
{
    public function __construct(
        public string $status,          // confirmed | pending | failed | deduped
        public string $reference,
        public ?string $providerTxId = null,
        public bool $deduped = false,   // la solicitud se reconoció como duplicada
        public ?string $message = null,
    ) {}

    public static function confirmed(string $ref, string $txId): self
    {
        return new self('confirmed', $ref, $txId);
    }

    public static function pending(string $ref, ?string $msg = null): self
    {
        return new self('pending', $ref, null, false, $msg);
    }

    public static function failed(string $ref, string $msg): self
    {
        return new self('failed', $ref, null, false, $msg);
    }

    public static function deduped(string $ref, string $status, ?string $txId = null): self
    {
        return new self($status, $ref, $txId, true, 'Solicitud duplicada ignorada (idempotencia)');
    }
}
