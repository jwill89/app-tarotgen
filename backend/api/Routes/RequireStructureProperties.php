<?php

namespace Routes;

use OpenApi\Augmenter\Group;
use OpenApi\Spec\Property;
use OpenApi\Specification;
use OpenApi\Utils\PipeInterface;
use ReflectionClass;

/**
 * swagger-php augmenter, run when OpenApiSpec::builder() assembles the document
 * (`composer docs`, OpenApiSpecTest): marks every property of a
 * `Tarot\Structure` schema as required. AbstractStructure's jsonSerialize()
 * always sends all of them, but swagger-php leaves `required` unset, which makes
 * every field of the generated frontend types optional. A schema that lists
 * its own `required` is left alone, and so is a property marked
 * `x: ['admin-only' => true]`: the public endpoints strip it.
 *
 * @implements PipeInterface<Specification>
 */
final class RequireStructureProperties implements PipeInterface
{
    /** @return Specification */
    public function __invoke(mixed $payload): mixed
    {
        foreach ($payload->schemas as $schema) {
            // The class the schema is declared on; a schema declared anywhere
            // else (a property, OpenApiSpec's shared ones) has no Structure.
            $reflector = $schema->getReflector();
            if (
                !$reflector instanceof ReflectionClass
                || $reflector->getNamespaceName() !== 'Tarot\Structure'
                || $schema->properties === null
                || $schema->required !== null
            ) {
                continue;
            }

            $schema->required = array_values(array_filter(array_map(
                static fn(Property $property): ?string => ($property->x['admin-only'] ?? false) === true ? null : $property->property,
                $schema->properties,
            ), static fn(?string $name): bool => $name !== null));
        }

        return $payload;
    }

    /** After the built-in augmenters have named every property. */
    public function group(): Group
    {
        return Group::Augment;
    }
}
