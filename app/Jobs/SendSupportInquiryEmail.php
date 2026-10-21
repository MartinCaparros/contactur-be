<?php

namespace App\Jobs;

use App\Services\SupportInquiryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Reenvía una consulta pública al soporte configurado (R23). Solo transporta el
 * id: nombre, email y mensaje no viajan serializados en Redis.
 */
final class SendSupportInquiryEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public readonly int $inquiryId)
    {
        $this->onQueue('default');
    }

    public function handle(SupportInquiryService $inquiries): void
    {
        $inquiries->deliverEmail($this->inquiryId);
    }
}
