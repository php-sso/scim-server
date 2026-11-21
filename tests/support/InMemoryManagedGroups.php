<?php

declare(strict_types=1);

namespace PhpSso\ScimServer\Tests\Support;

use PhpSso\ScimServer\Contract\ManagedGroups;
use PhpSso\ScimServer\GroupPage;
use PhpSso\ScimServer\ScimFilter;
use PhpSso\ScimServer\ScimGroup;
use PhpSso\ScimServer\UniquenessViolation;

final class InMemoryManagedGroups implements ManagedGroups
{
    /** Simulates a concurrent request that took the value between the server's check and the store. */
    public bool $raceOnWrite = false;

    public function __construct(private World $world)
    {
    }

    /** The id of the client that manages this group, or null. */
    public function managedBy(int $groupId): ?int
    {
        return $this->world->managedGroups[$groupId]['client'] ?? null;
    }

    public function find(int $clientId, int $groupId): ?ScimGroup
    {
        $managed = $this->world->managedGroups[$groupId] ?? null;

        return $managed !== null && $managed['client'] === $clientId ? $this->group($groupId) : null;
    }

    public function findByDisplayName(int $clientId, string $displayName): ?ScimGroup
    {
        foreach ($this->world->managedGroups as $id => $managed) {
            if ($managed['client'] === $clientId && strcasecmp($managed['displayName'], $displayName) === 0) {
                return $this->group($id);
            }
        }

        return null;
    }

    public function findByExternalId(int $clientId, string $externalId): ?ScimGroup
    {
        foreach ($this->world->managedGroups as $id => $managed) {
            if ($managed['client'] === $clientId && $managed['externalId'] === $externalId) {
                return $this->group($id);
            }
        }

        return null;
    }

    public function list(int $clientId, ?ScimFilter $filter, int $start, int $count): GroupPage
    {
        $matches = [];
        foreach ($this->world->managedGroups as $id => $managed) {
            if ($managed['client'] === $clientId && ($filter === null || $this->matches($id, $filter))) {
                $matches[$id] = $this->world->groups[$id]['name'];
            }
        }
        asort($matches);
        $ids = array_slice(array_keys($matches), max(0, $start - 1), $count);

        return new GroupPage(count($matches), array_map($this->group(...), $ids));
    }

    public function attach(int $clientId, int $groupId, string $displayName, ?string $externalId): void
    {
        $this->assertFree($clientId, $groupId, $displayName, $externalId);
        $this->world->managedGroups[$groupId] = ['client' => $clientId, 'displayName' => $displayName, 'externalId' => $externalId];
    }

    public function update(int $groupId, string $displayName, ?string $externalId): void
    {
        $this->assertFree($this->world->managedGroups[$groupId]['client'], $groupId, $displayName, $externalId);
        $this->world->managedGroups[$groupId] = ['client' => $this->world->managedGroups[$groupId]['client'], 'displayName' => $displayName, 'externalId' => $externalId];
    }

    public function isManaged(int $groupId): bool
    {
        return isset($this->world->managedGroups[$groupId]);
    }

    public function members(int $groupId): array
    {
        return $this->world->members[$groupId] ?? [];
    }

    public function addMembers(int $groupId, array $subs): void
    {
        $this->world->members[$groupId] = array_values(array_unique([...$this->members($groupId), ...$subs]));
    }

    public function removeMembers(int $groupId, array $subs): void
    {
        $this->world->members[$groupId] = array_values(array_diff($this->members($groupId), $subs));
    }

    public function replaceMembers(int $groupId, array $subs): void
    {
        $this->world->members[$groupId] = array_values(array_unique($subs));
    }

    private function assertFree(int $clientId, int $groupId, string $displayName, ?string $externalId): void
    {
        if ($this->raceOnWrite) {
            throw new UniquenessViolation('Taken by a concurrent request.');
        }
        foreach ($this->world->managedGroups as $other => $managed) {
            if ($other === $groupId || $managed['client'] !== $clientId) {
                continue;
            }
            if (strcasecmp($managed['displayName'], $displayName) === 0 || ($externalId !== null && $managed['externalId'] === $externalId)) {
                throw new UniquenessViolation('Taken.');
            }
        }
    }

    private function matches(int $id, ScimFilter $filter): bool
    {
        $managed = $this->world->managedGroups[$id];

        return match ($filter->attr) {
            'displayName' => strcasecmp($managed['displayName'], $filter->value) === 0,
            'externalId' => $managed['externalId'] === $filter->value,
            'id' => (string) $id === $filter->value,
            default => false,
        };
    }

    private function group(int $id): ScimGroup
    {
        $managed = $this->world->managedGroups[$id];
        $group = $this->world->groups[$id];

        return new ScimGroup($id, $group['name'], $group['description'], $managed['displayName'], $managed['externalId']);
    }
}
