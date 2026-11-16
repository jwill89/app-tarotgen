<?php

namespace Tarot\Structure;

use OpenApi\Spec as OAS;

/**
 * One of a user's passkeys as their account page lists it. The public key and
 * signature counter aren't here: only signing in needs them (PasskeyCredential).
 *
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
#[OAS\Schema(description: "A passkey registered to the signed-in user's account.")]
class Passkey extends AbstractStructure
{
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $passkey_id = 0;

    #[OAS\Property(schema: new OAS\Schema(type: 'string', description: 'The WebAuthn credential id, base64url-encoded.'))]
    public private(set) string $credential_id = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $name = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'string', format: 'date-time', examples: ['2026-11-07T09:18:09Z']))]
    public private(set) string $created_at = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'string', format: 'date-time', nullable: true, examples: ['2026-11-07T09:18:09Z']))]
    public private(set) ?string $last_used_at = null;
}
