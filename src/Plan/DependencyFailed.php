<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan;

use RuntimeException;

/**
 * A step the rest of the plan is built on top of did not work.
 *
 * Most failures are reported and stepped over, because a half-applied run is
 * harder to reason about than one skipped file. Dependencies are the exception:
 * they run first, before anything has been rewritten, and everything after them
 * assumes they worked. Rewriting every view onto components that failed to
 * install would leave an application that neither builds nor explains itself.
 *
 * Aborting here is cheap precisely because it is early — nothing has been touched
 * yet, so there is nothing to unpick.
 */
final class DependencyFailed extends RuntimeException {}
