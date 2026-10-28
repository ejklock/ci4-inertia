<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Support;

use BackedEnum;
use Config\Format;
use InvalidArgumentException;
use JsonSerializable;
use stdClass;

/**
 * The rule `Config\Inertia::$strictProps` applies to a value that reaches the page: it is null,
 * a scalar, an array, a plain `stdClass` data bag or a backed enum that is not `JsonSerializable`,
 * and so is everything inside it. A `stdClass` that holds itself, directly or through another
 * `stdClass`, is refused too, and so is a value that nests arrays and `stdClass` objects deeper than
 * the app's `Config\Format::$jsonEncodeDepth`, the depth its JSON response encodes at; the setting
 * is read each time a check starts.
 *
 * @internal
 */
final class StrictPropValue
{
    /**
     * The exception for the first value under `$path` the rule refuses, or null when the value
     * is fine. It names the dot path and the type, never the value. Keys of arrays and
     * properties of a `stdClass` extend the path, so a data-keyed array puts its keys in it.
     * `$label` says what the value is in the message, such as `prop` or `flash value`. `$level` is
     * the level the value sits at, the top of a prop being level 1.
     */
    public static function refusal(mixed $value, string $path, string $label = 'prop', int $level = 1): ?InvalidArgumentException
    {
        return self::inspect($value, $path, $label, [], $level, config(Format::class)->jsonEncodeDepth, self::startOf($path));
    }

    /**
     * The refusal of a value that nests deeper than `$limit` levels. It names where the value
     * starts, never the deep path, which for a value that holds itself would run to the limit.
     */
    public static function tooDeep(string $path, int $limit, string $label = 'prop'): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'Inertia %s "%s" nests deeper than %d levels when strictProps is on.',
            $label,
            self::startOf($path),
            $limit,
        ));
    }

    private static function startOf(string $path): string
    {
        return explode('.', $path, 2)[0];
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
     * @param int              $depth     The level of this value, the value at the starting path being level 1.
     * @param int              $limit     The deepest level a container may sit at.
     * @param string           $start     The path the check began at, which names a too-deep refusal.
     */
    private static function inspect(mixed $value, string $path, string $label, array $enclosing, int $depth, int $limit, string $start): ?InvalidArgumentException
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
            // An array has no identity to track, so a reference that makes it hold itself is
            // stopped at the depth the JSON response itself stops at.
            if ($depth > $limit) {
                return self::tooDeep($start, $limit, $label);
            }

            foreach ($value as $key => $inner) {
                $refusal = self::inspect($inner, $path . '.' . $key, $label, $enclosing, $depth + 1, $limit, $start);

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
