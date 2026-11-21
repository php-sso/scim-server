<?php

declare(strict_types=1);

namespace PhpSso\ScimServer;

use PhpSso\ScimServer\Contract\Accounts;
use PhpSso\ScimServer\Contract\GroupDirectory;
use PhpSso\ScimServer\Contract\ManagedGroups;
use PhpSso\ScimServer\Contract\ManagedUsers;

/**
 * What provisioning clients may do to groups. A client manages the groups it created and only puts people it manages in
 * them. Group names reach apps in the `groups` claim, so a client cannot take over a group that already exists unless it was
 * allowed to, and its names can carry a prefix that keeps them apart from yours. Deleting a group deletes it (and with it the
 * roles given to it), because a group that is only ignored would still count for apps.
 */
final class ScimGroupService
{
    public const GROUP_SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:Group';

    public function __construct(
        private GroupDirectory $directory,
        private ManagedGroups $managed,
        private ManagedUsers $managedUsers,
        private Accounts $accounts,
        private string $baseUrl,
    ) {
    }

    /**
     * @param array<mixed> $payload
     * @return ScimGroup the managed group
     * @throws ScimException
     */
    public function create(ProvisioningClient $client, array $payload): ScimGroup
    {
        $p = $this->profile($payload);
        $name = $this->groupName($client, $p['displayName']);
        $members = $this->resolveMembers($client, $p['members'] ?? []);

        if ($this->managed->findByDisplayName($client->id, $p['displayName']) !== null) {
            throw new ScimException(409, 'That displayName already exists.', 'uniqueness');
        }
        if ($p['externalId'] !== null && $this->managed->findByExternalId($client->id, $p['externalId']) !== null) {
            throw new ScimException(409, 'That externalId already exists.', 'uniqueness');
        }

        $existing = $this->directory->findByName($name);
        if ($existing !== null) {
            if ($this->managed->isManaged($existing->id) || !$client->adoptGroups) {
                throw new ScimException(409, 'A group with that name already exists.', 'uniqueness');
            }
            $id = $existing->id; // a group made some other way, which this client may take over
        } else {
            $id = $this->directory->create($name, mb_substr('Provisioned by ' . $client->name, 0, 255));
        }
        try {
            $this->managed->attach($client->id, $id, $p['displayName'], $p['externalId']);
        } catch (UniquenessViolation) {
            throw new ScimException(409, 'That displayName or externalId already exists.', 'uniqueness');
        }
        $this->managed->replaceMembers($id, $members);

        return $this->get($client, $id);
    }

    /** @throws ScimException */
    public function get(ProvisioningClient $client, int $id): ScimGroup
    {
        return $this->managed->find($client->id, $id) ?? throw new ScimException(404, 'No such group.');
    }

    /**
     * @param array<mixed> $payload the whole new representation: the members it lists are the members
     * @throws ScimException
     */
    public function replace(ProvisioningClient $client, int $id, array $payload): ScimGroup
    {
        $group = $this->get($client, $id);
        $p = $this->profile($payload);
        $members = $this->resolveMembers($client, $p['members'] ?? []);
        $this->save($client, $group, $p['displayName'], $p['externalId']);
        $this->managed->replaceMembers($id, $members);

        return $this->get($client, $id);
    }

    /**
     * @param list<mixed> $operations
     * @return array{group: ScimGroup, ignored: list<string>}
     * @throws ScimException
     */
    public function patch(ProvisioningClient $client, int $id, array $operations): array
    {
        $group = $this->get($client, $id);
        $read = ScimGroupPatch::read($operations);
        // Check every member first, so a bad one changes nothing at all.
        $steps = [];
        foreach ($read['members'] as $step) {
            $steps[] = ['op' => $step['op'], 'subs' => $step['subs'] === null ? null : ($step['op'] === 'remove' ? $step['subs'] : $this->resolveMembers($client, $step['subs']))];
        }

        $c = $read['changes'];
        if (array_key_exists('displayName', $c) || array_key_exists('externalId', $c)) {
            $externalId = array_key_exists('externalId', $c) ? ($c['externalId'] === '' ? null : $c['externalId']) : $group->externalId;
            $this->save($client, $group, (string) ($c['displayName'] ?? $group->displayName), $externalId);
        }
        foreach ($steps as $step) {
            match ($step['op']) {
                'add' => $this->managed->addMembers($id, $step['subs'] ?? []),
                'replace' => $this->managed->replaceMembers($id, $step['subs'] ?? []),
                default => $step['subs'] === null ? $this->managed->replaceMembers($id, []) : $this->managed->removeMembers($id, $step['subs']),
            };
        }

        return ['group' => $this->get($client, $id), 'ignored' => $read['ignored']];
    }

    /** @throws ScimException */
    public function delete(ProvisioningClient $client, int $id): void
    {
        $this->get($client, $id);
        $this->directory->delete($id); // memberships, role assignments and the SCIM record go with it
    }

    /** @return array<string, mixed> the SCIM representation */
    public function representation(ScimGroup $group): array
    {
        $members = [];
        foreach ($this->managed->members($group->id) as $sub) {
            $members[] = ['value' => $sub, 'display' => $this->accounts->displayName($sub) ?? $sub, 'type' => 'User', '$ref' => rtrim($this->baseUrl, '/') . '/scim/v2/Users/' . $sub];
        }

        return array_filter([
            'schemas' => [self::GROUP_SCHEMA],
            'id' => (string) $group->id,
            'externalId' => $group->externalId,
            'displayName' => $group->displayName,
            'members' => $members,
            'meta' => ['resourceType' => 'Group', 'location' => rtrim($this->baseUrl, '/') . '/scim/v2/Groups/' . $group->id],
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * Renames.
     * @throws ScimException
     */
    private function save(ProvisioningClient $client, ScimGroup $group, string $displayName, ?string $externalId): void
    {
        $displayName = trim($displayName);
        $name = $this->groupName($client, $displayName);
        $other = $this->managed->findByDisplayName($client->id, $displayName);
        if ($other !== null && $other->id !== $group->id) {
            throw new ScimException(409, 'That displayName already exists.', 'uniqueness');
        }
        $other = $externalId === null ? null : $this->managed->findByExternalId($client->id, $externalId);
        if ($other !== null && $other->id !== $group->id) {
            throw new ScimException(409, 'That externalId already exists.', 'uniqueness');
        }
        $named = $this->directory->findByName($name);
        if ($named !== null && $named->id !== $group->id) {
            throw new ScimException(409, 'A group with that name already exists.', 'uniqueness');
        }
        if ($name !== $group->name) {
            $this->directory->update($group->id, $name, $group->description);
        }
        try {
            $this->managed->update($group->id, $displayName, $externalId);
        } catch (UniquenessViolation) {
            throw new ScimException(409, 'That displayName or externalId already exists.', 'uniqueness');
        }
    }

    /**
     * The name apps see: the client's prefix, then the name it sent with every run of characters we do not allow in a group
     * name turned into a dash ("All Employees" becomes "All-Employees").
     *
     * @throws ScimException
     */
    private function groupName(ProvisioningClient $client, string $displayName): string
    {
        $clean = trim((string) preg_replace('/[^A-Za-z0-9_.:-]+/', '-', $displayName), '-');
        $name = $client->groupPrefix . $clean;
        if ($clean === '' || preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $name) !== 1) {
            throw new ScimException(400, "\"{$displayName}\" cannot be used as a group name: it needs 1 to 64 letters, digits or _ . : - (spaces become dashes, and the client's prefix counts).", 'invalidValue');
        }

        return $name;
    }

    /**
     * The people to put in the group: only ones this client manages.
     *
     * @param mixed $members the `members` of a request, or ids
     * @return list<string>
     * @throws ScimException
     */
    private function resolveMembers(ProvisioningClient $client, mixed $members): array
    {
        $subs = [];
        foreach (ScimGroupPatch::subs($members) as $sub) {
            if ($this->managedUsers->find($client->id, $sub) === null) {
                throw new ScimException(400, "Member \"{$sub}\" is not a person this client manages. Provision the person first.", 'invalidValue');
            }
            $subs[] = $sub;
        }

        return array_values(array_unique($subs));
    }

    /**
     * @param array<mixed> $payload
     * @return array{displayName: string, externalId: ?string, members: mixed}
     * @throws ScimException
     */
    private function profile(array $payload): array
    {
        $displayName = is_string($payload['displayName'] ?? null) ? trim($payload['displayName']) : '';
        if ($displayName === '' || mb_strlen($displayName) > 255) {
            throw new ScimException(400, 'displayName is required.', 'invalidValue');
        }
        $externalId = is_string($payload['externalId'] ?? null) && trim($payload['externalId']) !== '' ? trim($payload['externalId']) : null;

        return ['displayName' => $displayName, 'externalId' => $externalId, 'members' => $payload['members'] ?? []];
    }
}
