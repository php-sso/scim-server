<?php

declare(strict_types=1);

namespace PhpSso\ScimServer\Contract;

/** Where requests from provisioning clients are noted, so an administrator can see what a provider did. */
interface EventLog
{
    public function record(int $clientId, string $method, string $path, int $status, string $detail): void;
}
