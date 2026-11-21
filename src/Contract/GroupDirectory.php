<?php

declare(strict_types=1);

namespace PhpSso\ScimServer\Contract;

use PhpSso\ScimServer\DirectoryGroup;

/** The application's own groups, the ones apps see in the `groups` claim. */
interface GroupDirectory
{
    public function findByName(string $name): ?DirectoryGroup;

    /** @return int the new group's id */
    public function create(string $name, string $description): int;

    public function update(int $id, string $name, string $description): void;

    /** Removes the group together with its memberships and everything granted to it. */
    public function delete(int $id): void;
}
