<?php

declare(strict_types=1);

namespace PhpSso\ScimServer;

/** A group in the application's own directory, whether or not any client manages it. */
final class DirectoryGroup
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $description,
    ) {
    }
}
