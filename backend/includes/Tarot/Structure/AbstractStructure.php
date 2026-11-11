<?php

namespace Tarot\Structure;

use JsonSerializable;
use ReflectionClass;
use ReflectionProperty;

/**
 * Base of the entity classes: a generic constructor that sets properties from
 * an array, and JSON serialization of the public properties.
 */
abstract class AbstractStructure implements JsonSerializable
{
    /**
     * Each class's public properties — its serialized shape — cached to avoid
     * repeated reflection.
     *
     * @var array<class-string, list<ReflectionProperty>>
     */
    private static array $propertyCache = [];

    /**
     * @param array<string,mixed> $params An associative array of properties to set on the object.
     */
    public function __construct(array $params = [])
    {
        $this->setProperties($params);
    }

    /**
     * Sets each property the array names; keys that aren't properties are ignored.
     *
     * @param array<string,mixed> $params An associative array of properties to set on the object.
     */
    public function setProperties(array $params = []): void
    {
        foreach ($params as $property => $value) {
            if (property_exists($this, $property)) {
                // Properties use asymmetric visibility (public read, private(set)),
                // so a direct `$this->$property =` from this parent scope would be
                // rejected. Reflection writes them across scope.
                new ReflectionProperty($this, $property)->setValue($this, $value);
            }
        }
    }

    /**
     * The set hook of a property stored as a JSON list of objects (spread
     * positions): FETCH_CLASS hands it the column's text, the array constructor
     * the already-decoded list. A malformed document throws rather than reading
     * back as an empty spread.
     *
     * @param array<mixed>|string $value
     * @return list<array<string,mixed>>
     */
    protected static function jsonList(array|string $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value;

        // A list of objects by construction: NormalizesPositions wrote it, or
        // the caller built it — JSON itself can't promise that shape.
        /** @var list<array<string,mixed>> $list */
        $list = is_array($decoded) ? $decoded : [];

        return $list;
    }

    /**
     * The object's public properties as an associative array — the API shape.
     *
     * @return array<string,mixed>
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        $properties = self::$propertyCache[static::class]
            ??= new ReflectionClass($this)->getProperties(ReflectionProperty::IS_PUBLIC);

        $data = [];
        foreach ($properties as $property) {
            $data[$property->getName()] = $property->getValue($this);
        }

        return $data;
    }

    /**
     * The serialized object minus some properties — for public responses that
     * must not carry fields only admin views need (e.g. `submitted_by`).
     *
     * @return array<string,mixed>
     */
    public function jsonSerializeWithout(string ...$properties): array
    {
        return array_diff_key($this->jsonSerialize(), array_flip($properties));
    }
}
