<?php

namespace Tarot\Structure;

use OpenApi\Spec as OAS;

/**
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
#[OAS\Schema(description: 'A contact-form submission.')]
class Contact extends AbstractStructure
{
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $contact_id = 0;

    #[OAS\Property(schema: new OAS\Schema(type: 'integer', nullable: true))]
    public private(set) ?int $user_id = null;

    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $name = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $email = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $message = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $is_read = 0;

    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $submitted_at = '';

    /** Whether the message has been read (the stored column is a 0/1 int). */
    public function isRead(): bool
    {
        return (bool) $this->is_read;
    }
}
