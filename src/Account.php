<?php

declare(strict_types=1);

namespace PhpSso\ScimServer;

/** An account in the application, whether or not any client manages it. */
final class Account
{
    public function __construct(
        public readonly string $sub,
        public readonly bool $isAdmin,
    ) {
    }
}
