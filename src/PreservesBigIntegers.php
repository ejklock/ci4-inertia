<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

use BackedEnum;
use JsonSerializable;
use ReflectionClass;
use SplObjectStorage;
use stdClass;
use UnitEnum;

/**
 * Wraps the integers a JavaScript client cannot hold exactly, for a response that opted in.
 *
 * @internal
 */
trait PreservesBigIntegers
{
    /**
     * The key a big integer is transported under.
     */
    protected const BIG_INTEGER_KEY = '$bigint';

    /**
     * The largest integer JavaScript represents without losing precision.
     */
    protected const MAX_SAFE_INTEGER = 9007199254740991;

    /**
     * Whether integers outside JavaScript's safe range are preserved.
     */
    protected bool $preserveBigIntegers = false;

    /**
     * Wraps big integers only when this response opted in.
     */
    protected function encodeBigIntegersWhenEnabled(mixed $value): mixed
    {
        return $this->preserveBigIntegers ? $this->encodeBigIntegers($value) : $value;
    }

    /**
     * Wraps integers outside JavaScript's safe integer range as a marker, so the client can
     * revive them as native BigInt values without losing precision when the JSON is parsed.
     *
     * @param SplObjectStorage<object, mixed>|null $seen
     */
    protected function encodeBigIntegers(mixed $value, ?SplObjectStorage $seen = null): mixed
    {
        if (is_int($value)) {
            return $value > self::MAX_SAFE_INTEGER || $value < -self::MAX_SAFE_INTEGER
                ? [self::BIG_INTEGER_KEY => (string) $value]
                : $value;
        }

        if (is_array($value)) {
            return $this->encodeBigIntegersInArray($value, $seen);
        }

        if (! is_object($value)) {
            return $value;
        }

        /** @var SplObjectStorage<object, mixed> $ancestors */
        $ancestors = $seen ?? new SplObjectStorage();

        // Only ancestors are tracked, so a self-reference stops here and is left for json_encode
        // to report, while a value shared by two branches is still encoded in both.
        if ($ancestors->offsetExists($value)) {
            return $value;
        }

        $ancestors->offsetSet($value);

        $encoded = $this->encodeBigIntegersInObject($value, $ancestors);

        $ancestors->offsetUnset($value);

        return $encoded;
    }

    /**
     * @param array<array-key, mixed>              $value
     * @param SplObjectStorage<object, mixed>|null $seen
     *
     * @return array<array-key, mixed>
     */
    protected function encodeBigIntegersInArray(array $value, ?SplObjectStorage $seen): array
    {
        foreach ($value as $key => $nested) {
            $value[$key] = $this->encodeBigIntegers($nested, $seen);
        }

        return $value;
    }

    /**
     * Wraps the big integers an object holds, unwrapping it the way json_encode would so the
     * shape it emits is unchanged; an object is cast back so it still serializes as a JSON object.
     *
     * @param SplObjectStorage<object, mixed> $seen
     */
    protected function encodeBigIntegersInObject(object $value, SplObjectStorage $seen): mixed
    {
        if ($value instanceof BackedEnum) {
            return $this->encodeBigIntegers($value->value, $seen);
        }

        // A pure enum has no JSON representation; walking its properties would invent one, so
        // it is left for json_encode to reject.
        if ($value instanceof UnitEnum) {
            return $value;
        }

        if ($value instanceof JsonSerializable) {
            return $this->encodeBigIntegers($value->jsonSerialize(), $seen);
        }

        // An internal class such as DateTime, or a class extending one, serializes through its
        // own handler; walking its public properties would change the shape, not just the integers.
        if (! $this->hasPlainJsonRepresentation($value)) {
            return $value;
        }

        return (object) $this->encodeBigIntegersInArray(get_object_vars($value), $seen);
    }

    /**
     * Whether the object's public properties are what json_encode emits.
     */
    protected function hasPlainJsonRepresentation(object $value): bool
    {
        if ($value instanceof stdClass) {
            return true;
        }

        for ($class = new ReflectionClass($value); $class !== false; $class = $class->getParentClass()) {
            if ($class->isInternal()) {
                return false;
            }
        }

        return true;
    }
}
