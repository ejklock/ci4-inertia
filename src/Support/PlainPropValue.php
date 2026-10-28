<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Support;

use Config\Format;
use InvalidArgumentException;
use stdClass;

/**
 * The plain-data rule that keeps an entity off the page at any depth. A prop handed to
 * `render()` or `share()` may hold only null, scalars and arrays inside an array; the value
 * a prop object resolves to may also hold a `stdClass` data bag, because the package's own
 * `errors` prop resolves to one. Both checks share one walk so the two rules cannot drift.
 *
 * The deepest level of arrays and `stdClass` objects a prop may nest is the app's own
 * `Config\Format::$jsonEncodeDepth` (512 by default), the depth its JSON response encodes at.
 * The setting is read each time a walk starts.
 *
 * @internal
 */
final class PlainPropValue
{
    /**
     * Asserts that an array handed in as a prop holds only null, scalars and arrays at every depth.
     * A value that is not an array has nothing inside it to check.
     *
     * @throws InvalidArgumentException When something nested is not null, a scalar or an array; the
     *                                  message names the dot path and the type, never the value. Also when
     *                                  arrays nest beyond the limit; that message names the limit and only the prop key.
     */
    public static function assertGiven(string $key, mixed $value): void
    {
        if (is_array($value)) {
            self::inspect($value, $key, $key, false, [], 1, self::depthLimit());
        }
    }

    /**
     * Asserts that what a prop object resolved to is null, a scalar, an array or a plain
     * `stdClass`, and so is everything inside it.
     *
     * @throws InvalidArgumentException When something is refused, or a `stdClass` holds an enclosing
     *                                  `stdClass`; the message names the dot path and the type, never the value.
     *                                  Also when arrays and `stdClass` objects nest beyond the limit; that
     *                                  message names the limit and only the prop key.
     */
    public static function assertResolved(string $key, mixed $value): void
    {
        self::inspect($value, $key, $key, true, [], 1, self::depthLimit());
    }

    /**
     * @param array<int, true> $enclosing The `stdClass` objects on the path down to this value, by object id.
     * @param int              $depth     The level of this value, the prop value itself being level 1.
     * @param int              $limit     The deepest level a container may sit at.
     */
    private static function inspect(mixed $value, string $key, string $path, bool $resolved, array $enclosing, int $depth, int $limit): void
    {
        $isBag = $resolved && is_object($value) && $value::class === stdClass::class;

        if ($isBag && isset($enclosing[spl_object_id($value)])) {
            throw self::refusal($path, $resolved, $value);
        }

        if ($isBag) {
            $enclosing[spl_object_id($value)] = true;
        }

        if (is_array($value) || $isBag) {
            // An array has no identity to track, so a reference that makes it hold itself is
            // stopped here, at the depth the JSON response itself stops at.
            if ($depth > $limit) {
                throw new InvalidArgumentException(sprintf('Inertia prop "%s" nests deeper than %d levels.', $key, $limit));
            }

            foreach ($value as $name => $inner) {
                self::inspect($inner, $key, $path . '.' . $name, $resolved, $enclosing, $depth + 1, $limit);
            }

            return;
        }

        if ($value !== null && ! is_scalar($value)) {
            throw self::refusal($path, $resolved, $value);
        }
    }

    private static function depthLimit(): int
    {
        return config(Format::class)->jsonEncodeDepth;
    }

    private static function refusal(string $path, bool $resolved, mixed $value): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            $resolved
                ? 'Inertia prop "%s" must resolve to only scalars, arrays, null and stdClass objects, %s given.'
                : 'Inertia prop "%s" must hold only scalars, arrays and null, %s given.',
            $path,
            get_debug_type($value),
        ));
    }
}
