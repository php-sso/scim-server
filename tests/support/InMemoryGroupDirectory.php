<?php

declare(strict_types=1);

namespace PhpSso\ScimServer\Tests\Support;

use PhpSso\ScimServer\Contract\GroupDirectory;
use PhpSso\ScimServer\DirectoryGroup;

final class InMemoryGroupDirectory implements GroupDirectory
{
    public function __construct(private World $world)
    {
    }

    /** Adds a group the way an administrator would, outside SCIM. @return int its id */
    public function add(string $name, string $description = ''): int
    {
        return $this->create($name, $description);
    }

    public function exists(int $id): bool
    {
        return isset($this->world->groups[$id]);
    }

    public function nameOf(int $id): ?string
    {
        return $this->world->groups[$id]['name'] ?? null;
    }

    public function findByName(string $name): ?DirectoryGroup
    {
        foreach ($this->world->groups as $id => $group) {
            if (strcasecmp($group['name'], $name) === 0) {
                return new DirectoryGroup($id, $group['name'], $group['description']);
            }
        }

        return null;
    }

    public function create(string $name, string $description): int
    {
        $id = ++$this->world->groupCounter;
        $this->world->groups[$id] = ['name' => $name, 'description' => $description];

        return $id;
    }

    public function update(int $id, string $name, string $description): void
    {
        $this->world->groups[$id] = ['name' => $name, 'description' => $description];
    }

    public function delete(int $id): void
    {
        $this->world->removeGroup($id);
    }
}
