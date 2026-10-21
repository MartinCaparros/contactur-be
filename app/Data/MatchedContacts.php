<?php

namespace App\Data;

use App\Models\Profile;

/**
 * Contactos declarados tras un match aceptado (R16). `actor` es el perfil
 * participante que consulta y `counterpart` la otra parte; ambos con `contact`
 * (profile_contacts) cargado. Nunca el email de login de la cuenta.
 */
final readonly class MatchedContacts
{
    public function __construct(
        public string $contactRequestId,
        public Profile $actor,
        public Profile $counterpart,
    ) {}
}
