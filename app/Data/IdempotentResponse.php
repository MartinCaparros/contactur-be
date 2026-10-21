<?php

namespace App\Data;

/** Respuesta HTTP persistible: la original o su reproducción por Idempotency-Key. */
final readonly class IdempotentResponse
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public int $status,
        public array $body,
        public bool $replayed,
    ) {}
}
