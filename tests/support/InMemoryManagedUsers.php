<?php

declare(strict_types=1);

namespace PhpSso\ScimServer\Tests\Support;

use PhpSso\ScimServer\Contract\ManagedUsers;
use PhpSso\ScimServer\ScimFilter;
use PhpSso\ScimServer\ScimUser;
use PhpSso\ScimServer\UniquenessViolation;
use PhpSso\ScimServer\UserPage;

final class InMemoryManagedUsers implements ManagedUsers
{
    /** Simulates a concurrent request that took the value between the server's check and the store. */
    public bool $raceOnWrite = false;

    public function __construct(private World $world)
    {
    }

    /** The id of the client that manages this person, or null. */
    public function managedBy(string $sub): ?int
    {
        return $this->world->managedUsers[$sub]['client'] ?? null;
    }

    public function find(int $clientId, string $sub): ?ScimUser
    {
        $managed = $this->world->managedUsers[$sub] ?? null;

        return $managed !== null && $managed['client'] === $clientId ? $this->user($sub) : null;
    }

    public function findByUserName(int $clientId, string $userName): ?ScimUser
    {
        foreach ($this->world->managedUsers as $sub => $managed) {
            if ($managed['client'] === $clientId && strcasecmp($managed['userName'], $userName) === 0) {
                return $this->user($sub);
            }
        }

        return null;
    }

    public function findByExternalId(int $clientId, string $externalId): ?ScimUser
    {
        foreach ($this->world->managedUsers as $sub => $managed) {
            if ($managed['client'] === $clientId && $managed['externalId'] === $externalId) {
                return $this->user($sub);
            }
        }

        return null;
    }

    public function list(int $clientId, ?ScimFilter $filter, int $start, int $count): UserPage
    {
        $matches = [];
        foreach ($this->world->managedUsers as $sub => $managed) {
            if ($managed['client'] === $clientId && ($filter === null || $this->matches($sub, $filter))) {
                $matches[$sub] = $managed['userName'];
            }
        }
        asort($matches);
        $subs = array_slice(array_keys($matches), max(0, $start - 1), $count);

        return new UserPage(count($matches), array_map($this->user(...), $subs));
    }

    public function attach(int $clientId, string $sub, string $userName, ?string $externalId, ?string $givenName, ?string $familyName): void
    {
        $this->assertFree($clientId, $sub, $userName, $externalId);
        $this->world->managedUsers[$sub] = ['client' => $clientId, 'userName' => $userName, 'externalId' => $externalId, 'givenName' => $givenName, 'familyName' => $familyName];
    }

    public function update(string $sub, string $userName, ?string $externalId, ?string $givenName, ?string $familyName): void
    {
        $this->assertFree($this->world->managedUsers[$sub]['client'], $sub, $userName, $externalId);
        $this->world->managedUsers[$sub] = ['client' => $this->world->managedUsers[$sub]['client'], 'userName' => $userName, 'externalId' => $externalId, 'givenName' => $givenName, 'familyName' => $familyName];
    }

    public function isManaged(string $sub): bool
    {
        return isset($this->world->managedUsers[$sub]);
    }

    private function assertFree(int $clientId, string $sub, string $userName, ?string $externalId): void
    {
        if ($this->raceOnWrite) {
            throw new UniquenessViolation('Taken by a concurrent request.');
        }
        foreach ($this->world->managedUsers as $other => $managed) {
            if ($other === $sub || $managed['client'] !== $clientId) {
                continue;
            }
            if (strcasecmp($managed['userName'], $userName) === 0 || ($externalId !== null && $managed['externalId'] === $externalId)) {
                throw new UniquenessViolation('Taken.');
            }
        }
    }

    private function matches(string $sub, ScimFilter $filter): bool
    {
        $managed = $this->world->managedUsers[$sub];
        $account = $this->world->accounts[$sub];

        return match ($filter->attr) {
            'userName' => strcasecmp($managed['userName'], $filter->value) === 0,
            'externalId' => $managed['externalId'] === $filter->value,
            'email' => strcasecmp($account['email'], $filter->value) === 0,
            'id' => $sub === $filter->value,
            default => false,
        };
    }

    private function user(string $sub): ScimUser
    {
        $managed = $this->world->managedUsers[$sub];
        $account = $this->world->accounts[$sub];

        return new ScimUser($sub, $managed['userName'], $managed['externalId'], $managed['givenName'], $managed['familyName'], $account['name'], $account['email'], $account['active'], $account['admin']);
    }
}
