<?php

namespace Tarot\Structure;

use OpenApi\Spec as OAS;

/**
 * A personal access token linking a plugin install to a user account — the
 * read-only projection shown in the "Connected Apps" list. The token hash is
 * never part of this shape.
 *
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes
 * (hydrated by PDO / the Data layer).
 */
#[OAS\Schema(description: 'A linked-plugin personal access token (no secret value exposed).')]
class PluginToken extends AbstractStructure
{
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $id = 0;

    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $user_id = 0;

    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $label = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $scope = 'account';

    #[OAS\Property(schema: new OAS\Schema(type: 'string', format: 'date-time', examples: ['2026-11-07T09:18:09Z']))]
    public private(set) string $created_at = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'string', format: 'date-time', nullable: true, examples: ['2026-11-07T09:18:09Z']))]
    public private(set) ?string $last_used_at = null;

    #[OAS\Property(schema: new OAS\Schema(type: 'string', format: 'date-time', nullable: true, examples: ['2026-11-07T09:18:09Z']))]
    public private(set) ?string $expires_at = null;

    #[OAS\Property(schema: new OAS\Schema(type: 'string', format: 'date-time', nullable: true, examples: ['2026-11-07T09:18:09Z']))]
    public private(set) ?string $revoked_at = null;
}
