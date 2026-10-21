<?php

namespace App\Repositories;

use App\Models\SupportInquiry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class SupportInquiryRepository
{
    public function create(
        string $name,
        string $email,
        ?string $category,
        string $message,
        string $ipHash,
        CarbonImmutable $now,
    ): SupportInquiry {
        return SupportInquiry::query()->create([
            'public_id' => (string) Str::ulid(),
            'name' => $name,
            'email' => $email,
            'category' => $category,
            'message' => $message,
            'status' => SupportInquiry::STATUS_PENDING,
            'ip_hash' => $ipHash,
            'created_at' => $now,
        ]);
    }

    /** Compensación cuando el push a la cola falla: no queda una consulta "aceptada". */
    public function delete(SupportInquiry $inquiry): void
    {
        SupportInquiry::query()->whereKey($inquiry->id)->delete();
    }

    public function lockById(int $id): ?SupportInquiry
    {
        return SupportInquiry::query()->whereKey($id)->lockForUpdate()->first();
    }

    /** Condicional: solo la primera entrega fija emailed_at. */
    public function markEmailed(SupportInquiry $inquiry, CarbonImmutable $now): bool
    {
        return SupportInquiry::query()
            ->whereKey($inquiry->id)
            ->whereNull('emailed_at')
            ->update(['emailed_at' => $now->utc()->format('Y-m-d H:i:s.uP')]) === 1;
    }

    /** Pendientes de responder creadas en [from, until) (métricas admin, R20). */
    public function countPending(?CarbonImmutable $createdFrom, ?CarbonImmutable $createdUntil): int
    {
        return SupportInquiry::query()
            ->where('status', SupportInquiry::STATUS_PENDING)
            ->when($createdFrom !== null, fn ($query) => $query->where('created_at', '>=', $createdFrom))
            ->when($createdUntil !== null, fn ($query) => $query->where('created_at', '<', $createdUntil))
            ->count();
    }
}
