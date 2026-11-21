<?php

declare(strict_types=1);

namespace PhpSso\ScimServer\Tests\Support;

/** The shared state behind the in-memory ports, so that deleting an account or a group cascades like it does for real. */
final class World
{
    /** @var array<string, array{name: string, email: string, admin: bool, active: bool}> keyed by sub */
    public array $accounts = [];

    /** @var array<string, int> how many times a person's sessions were ended */
    public array $sessionsEnded = [];

    /** @var array<int, array{name: string, description: string}> keyed by group id */
    public array $groups = [];

    /** @var array<string, array{client: int, userName: string, externalId: ?string, givenName: ?string, familyName: ?string}> keyed by sub */
    public array $managedUsers = [];

    /** @var array<int, array{client: int, displayName: string, externalId: ?string}> keyed by group id */
    public array $managedGroups = [];

    /** @var array<int, list<string>> the subs in each group */
    public array $members = [];

    public int $accountCounter = 0;
    public int $groupCounter = 0;

    public function removeAccount(string $sub): void
    {
        unset($this->accounts[$sub], $this->managedUsers[$sub]);
        foreach ($this->members as $id => $subs) {
            $this->members[$id] = array_values(array_diff($subs, [$sub]));
        }
    }

    public function removeGroup(int $id): void
    {
        unset($this->groups[$id], $this->managedGroups[$id], $this->members[$id]);
    }
}
