<?php

declare(strict_types=1);

namespace PhpSso\ScimServer;

/** A system allowed to provision people and groups, as the SCIM server needs to know it. */
final class ProvisioningClient
{
    /**
     * @param list<string> $domains the lower-case email domains this client may manage
     * @param string $groupPrefix put in front of every group name this client creates
     * @param bool $adoptExisting may take over an account that was made some other way
     * @param bool $adoptGroups may take over a group that was made some other way
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly array $domains,
        public readonly OnDelete $onDelete = OnDelete::Deactivate,
        public readonly bool $adoptExisting = false,
        public readonly string $groupPrefix = '',
        public readonly bool $adoptGroups = false,
    ) {
    }
}
