<?php

declare(strict_types=1);

namespace PhpSso\ScimServer;

use RuntimeException;

/** Thrown by a port when a store refuses a value another record already has (for example when two requests race). */
final class UniquenessViolation extends RuntimeException
{
}
