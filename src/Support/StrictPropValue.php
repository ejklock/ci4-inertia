<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Support;

use BackedEnum;
use InvalidArgumentException;
use JsonSerializable;
use stdClass;

/**
 * The rule `Config\Inertia::$strictProps` applies to a value that reaches the page: it is null,
 * a scalar, an array, a plain `stdClass` data bag or a backed enum that is not `JsonSerializable`,
 * and so is everything inside it. A `stdClass` that holds itself, directly or through another
 * `stdClass`, is refused too.
 *
 * @internal
 */
final class StrictPropValue
{
    /**
     * The exception for the first value under `$path` the rule refuses, or null when the value
     * is fine. It names the dot path and the type, never the value. Keys of arrays and
     * properties of a `stdClass` extend the path, so a data-keyed array puts its keys in it.
     * `$label` says what the value is in the message, such as `prop` or `flash value`.
     */
    public static function refusal(mixed $value, string $path, string $label = 'prop'): ?InvalidArgumentException
    {
        return self::inspect($value, $path, $label, []);
    }

    /**
     * @throws InvalidArgumentException When the value or anything inside it is refused.
     */
    public static function assert(mixed $value, string $path, string $label = 'prop'): void
    {
        $refusal = self::refusal($value, $path, $label);

        if ($refusal !== null) {
            throw $refusal;
        }
    }

    /**
     * @param array<int, true> $enclosing The `stdClass` objects on the path down to this value, by object id.
     */
    private static function inspect(mixed $value, string $path, string $label, array $enclosing): ?InvalidArgumentException
    {
        $isBag = is_object($value) && $value::class === stdClass::class;

        if ($isBag && isset($enclosing[spl_object_id($value)])) {
            return new InvalidArgumentException(sprintf(
                'Inertia %s "%s" refers back to an enclosing stdClass, which strictProps cannot check.',
                $label,
                $path,
            ));
        }

        if ($isBag) {
            $enclosing[spl_object_id($value)] = true;
        }

        if (is_array($value) || $isBag) {
            foreach ($value as $key => $inner) {
                $refusal = self::inspect($inner, $path . '.' . $key, $label, $enclosing);

                if ($refusal !== null) {
                    return $refusal;
                }
            }

            return null;
        }

        if (! is_object($value) || ($value instanceof BackedEnum && ! $value instanceof JsonSerializable)) {
            return null;
        }

        return new InvalidArgumentException(sprintf(
            'Inertia %s "%s" must hold only null, scalars, arrays, stdClass objects and backed enums that are not JsonSerializable when strictProps is on; %s given.',
            $label,
            $path,
            get_debug_type($value),
        ));
    }
}
