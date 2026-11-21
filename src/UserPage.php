<?php

declare(strict_types=1);

namespace PhpSso\ScimServer;

/** One page of the people a client manages. */
final class UserPage
{
    /** @param list<ScimUser> $users */
    public function __construct(
        public readonly int $total,
        public readonly array $users,
    ) {
    }
}
