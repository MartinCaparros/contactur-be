<?php

namespace Database\Factories;

use App\Enums\ProfileCategory;
use App\Models\DocumentType;
use App\Models\File;
use App\Models\Profile;
use App\Models\ProfileDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProfileDocument> */
final class ProfileDocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'profile_id' => Profile::factory(),
            'document_type_id' => static fn (): int => DocumentType::query()->firstOrCreate(
                ['category' => ProfileCategory::Guide->value, 'code' => 'factory-guide-license'],
                ['label' => 'Licencia de prueba', 'requires_expiration' => true, 'required' => true],
            )->id,
            'file_id' => File::factory(),
            'revision' => 1,
            'registration_number' => 'TEST-'.fake()->unique()->numerify('#####'),
            'valid_until' => now()->addYear()->toDateString(),
            'status' => ProfileDocument::STATUS_PENDING,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'rejection_reason' => null,
        ];
    }
}
