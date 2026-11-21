<?php

declare(strict_types=1);

namespace PhpSso\ScimServer\Contract;

use PhpSso\ScimServer\ProvisioningClient;

/** Knows which provisioning clients exist and which one a bearer token belongs to. */
interface ClientAuthenticator
{
    /** The switched-on client this token belongs to, or null. */
    public function authenticate(string $token): ?ProvisioningClient;
}
