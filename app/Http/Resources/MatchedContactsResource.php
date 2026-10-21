<?php

namespace App\Http\Resources;

use App\Data\MatchedContacts;
use App\Models\Profile;
use App\Models\ProfileContact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * MatchedContactsData del contrato: único lugar donde salen contact_email y
 * phone de profile_contacts. ContactRequestResource nunca los incluye.
 *
 * @mixin MatchedContacts
 */
final class MatchedContactsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'contact_request_id' => $this->contactRequestId,
            'actor' => self::contact($this->actor),
            'counterpart' => self::contact($this->counterpart),
        ];
    }

    /** @return array{profile_id: string, display_name: string, contact_email: string|null, phone: string|null} */
    private static function contact(Profile $profile): array
    {
        $contact = $profile->getRelation('contact');

        return [
            'profile_id' => $profile->public_id,
            'display_name' => $profile->display_name,
            'contact_email' => $contact instanceof ProfileContact ? $contact->contact_email : null,
            'phone' => $contact instanceof ProfileContact ? $contact->phone : null,
        ];
    }
}
