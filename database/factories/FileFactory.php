<?php

namespace Database\Factories;

use App\Models\File;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<File> */
final class FileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'owner_profile_id' => Profile::factory(),
            'uploader_user_id' => User::factory(),
            'kind' => 'document',
            'disk' => 'local',
            'private_path' => 'tests/'.Str::ulid().'.pdf',
            'original_filename' => 'documento-prueba.pdf',
            'mime' => 'application/pdf',
            'size' => 1024,
            'width' => null,
            'height' => null,
            'checksum' => hash('sha256', (string) Str::ulid()),
            'storage_deleted_at' => null,
            'created_at' => now(),
        ];
    }
}
