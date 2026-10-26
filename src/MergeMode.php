<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

/**
 * How the client combines a merged prop with the value it already holds.
 *
 * @internal Carried by `MergeState`; not part of the package's public API.
 */
enum MergeMode
{
    case Append;
    case Prepend;
    case Deep;
}
