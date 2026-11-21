<?php

declare(strict_types=1);

namespace PhpSso\ScimServer\Contract;

use PhpSso\ScimServer\ScimFilter;
use PhpSso\ScimServer\ScimUser;
use PhpSso\ScimServer\UniquenessViolation;
use PhpSso\ScimServer\UserPage;

/** Which people each provisioning client manages, with that client's own identifiers for them. */
interface ManagedUsers
{
    public function find(int $clientId, string $sub): ?ScimUser;

    /** Case-insensitive. */
    public function findByUserName(int $clientId, string $userName): ?ScimUser;

    public function findByExternalId(int $clientId, string $externalId): ?ScimUser;

    /** One page, ordered the same way every time. `$start` is 1-based. */
    public function list(int $clientId, ?ScimFilter $filter, int $start, int $count): UserPage;

    /**
     * Records that this client manages the person.
     *
     * @throws UniquenessViolation when the userName or externalId is already taken for this client
     */
    public function attach(int $clientId, string $sub, string $userName, ?string $externalId, ?string $givenName, ?string $familyName): void;

    /** @throws UniquenessViolation */
    public function update(string $sub, string $userName, ?string $externalId, ?string $givenName, ?string $familyName): void;

    /** Whether any client manages this person. */
    public function isManaged(string $sub): bool;
}
