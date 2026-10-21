<?php

namespace App\Models;

use App\Enums\ProfileCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property ProfileCategory $category
 * @property string $label
 * @property bool $requires_expiration
 * @property bool $required
 * @property string|null $jurisdiction
 */
#[Fillable(['code', 'category', 'label', 'requires_expiration', 'required', 'jurisdiction'])]
final class DocumentType extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => ProfileCategory::class,
            'requires_expiration' => 'boolean',
            'required' => 'boolean',
        ];
    }
}
