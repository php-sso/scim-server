<?php

declare(strict_types=1);

namespace PhpSso\ScimServer\Tests\Support;

use PhpSso\ScimServer\Contract\ClientAuthenticator;
use PhpSso\ScimServer\OnDelete;
use PhpSso\ScimServer\ProvisioningClient;

final class InMemoryClients implements ClientAuthenticator
{
    /** @var array<int, ProvisioningClient> */
    private array $clients = [];

    /** @var array<int, bool> */
    private array $enabled = [];

    /** @param list<string> $domains */
    public function add(string $name, array $domains, OnDelete $onDelete = OnDelete::Deactivate, bool $adoptExisting = false, string $groupPrefix = '', bool $adoptGroups = false): ProvisioningClient
    {
        $id = count($this->clients) + 1;
        $this->enabled[$id] = true;

        return $this->clients[$id] = new ProvisioningClient($id, $name, $domains, $onDelete, $adoptExisting, $groupPrefix, $adoptGroups);
    }

    public function tokenOf(ProvisioningClient $client): string
    {
        return 'token-' . $client->id;
    }

    public function switchOff(ProvisioningClient $client): void
    {
        $this->enabled[$client->id] = false;
    }

    public function authenticate(string $token): ?ProvisioningClient
    {
        foreach ($this->clients as $id => $client) {
            if ($token === $this->tokenOf($client) && $this->enabled[$id]) {
                return $client;
            }
        }

        return null;
    }
}
