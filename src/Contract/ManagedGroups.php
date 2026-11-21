<?php

declare(strict_types=1);

namespace PhpSso\ScimServer\Contract;

use PhpSso\ScimServer\GroupPage;
use PhpSso\ScimServer\ScimFilter;
use PhpSso\ScimServer\ScimGroup;
use PhpSso\ScimServer\UniquenessViolation;

/** Which groups each provisioning client manages, with that client's own names for them, and who is in them. */
interface ManagedGroups
{
    public function find(int $clientId, int $groupId): ?ScimGroup;

    public function findByDisplayName(int $clientId, string $displayName): ?ScimGroup;

    public function findByExternalId(int $clientId, string $externalId): ?ScimGroup;

    /** One page, ordered the same way every time. `$start` is 1-based. */
    public function list(int $clientId, ?ScimFilter $filter, int $start, int $count): GroupPage;

    /**
     * Records that this client manages the group.
     *
     * @throws UniquenessViolation when the displayName or externalId is already taken for this client
     */
    public function attach(int $clientId, int $groupId, string $displayName, ?string $externalId): void;

    /** @throws UniquenessViolation */
    public function update(int $groupId, string $displayName, ?string $externalId): void;

    /** Whether any client manages this group. */
    public function isManaged(int $groupId): bool;

    /** @return list<string> the `sub` of everyone in the group */
    public function members(int $groupId): array;

    /** @param list<string> $subs */
    public function addMembers(int $groupId, array $subs): void;

    /** @param list<string> $subs */
    public function removeMembers(int $groupId, array $subs): void;

    /**
     * Makes exactly these people the members.
     * @param list<string> $subs
     */
    public function replaceMembers(int $groupId, array $subs): void;
}
