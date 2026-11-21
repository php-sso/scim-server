<?php

declare(strict_types=1);

namespace PhpSso\ScimServer;

/** A list filter of the form `attribute eq "value"`, with the attribute already mapped to one of ours. */
final class ScimFilter
{
    /** @param string $attr `userName`, `externalId`, `email` or `id` for people; `displayName`, `externalId` or `id` for groups */
    public function __construct(
        public readonly string $attr,
        public readonly string $value,
    ) {
    }
}
