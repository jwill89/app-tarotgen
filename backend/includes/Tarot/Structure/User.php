<?php

namespace Tarot\Structure;

use OpenApi\Spec as OAS;

/**
 * A user account, in its *safe* shape: the password hash is deliberately not a
 * property here, so it can never leak through jsonSerialize(). The Data layer
 * reads the hash separately (findAuthByEmail) only where authentication needs it.
 *
 * Properties use asymmetric visibility (PHP 8.4): reads are public
 * (e.g. $user->user_id), while writes are private — PDO's FETCH_CLASS and the
 * array constructor still hydrate rows, but callers cannot mutate the entity.
 */
#[OAS\Schema(description: 'A user account in its safe shape (no secrets or hashes).')]
class User extends AbstractStructure
{
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $user_id = 0;

    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $email = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $display_name = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'boolean'))]
    public private(set) bool $is_active = false;

    #[OAS\Property(schema: new OAS\Schema(type: 'boolean'))]
    public private(set) bool $is_admin = false;

    #[OAS\Property(schema: new OAS\Schema(type: 'string', format: 'date-time', examples: ['2026-11-07T09:18:09Z']))]
    public private(set) string $registered_at = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'string', format: 'date-time', nullable: true, examples: ['2026-11-07T09:18:09Z']))]
    public private(set) ?string $last_login_at = null;

    #[OAS\Property(schema: new OAS\Schema(type: 'boolean'))]
    public private(set) bool $google_linked = false;

    #[OAS\Property(schema: new OAS\Schema(
        description: 'Whether the account has a password at all. An account made by signing in with Google has none until the user sets one through a password-reset link.',
        type: 'boolean'
    ))]
    public private(set) bool $has_password = false;

    #[OAS\Property(schema: new OAS\Schema(type: 'boolean'))]
    public private(set) bool $password_login_disabled = false;

    #[OAS\Property(schema: new OAS\Schema(type: 'boolean'))]
    public private(set) bool $has_passkeys = false;
}
