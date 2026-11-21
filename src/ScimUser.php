<?php

declare(strict_types=1);

namespace PhpSso\ScimServer;

/** A person a provisioning client manages: the account and the client's own identifiers for it. */
final class ScimUser
{
    public function __construct(
        public readonly string $sub,
        public readonly string $userName,
        public readonly ?string $externalId,
        public readonly ?string $givenName,
        public readonly ?string $familyName,
        public readonly string $name,
        public readonly string $email,
        public readonly bool $active,
        public readonly bool $isAdmin,
    ) {
    }
}
