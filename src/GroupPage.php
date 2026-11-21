<?php

declare(strict_types=1);

namespace PhpSso\ScimServer;

/** One page of the groups a client manages. */
final class GroupPage
{
    /** @param list<ScimGroup> $groups */
    public function __construct(
        public readonly int $total,
        public readonly array $groups,
    ) {
    }
}
