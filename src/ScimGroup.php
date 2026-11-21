<?php

declare(strict_types=1);

namespace PhpSso\ScimServer;

/** A group a provisioning client manages: the group as apps see it and the client's own names for it. */
final class ScimGroup
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $description,
        public readonly string $displayName,
        public readonly ?string $externalId,
    ) {
    }
}
