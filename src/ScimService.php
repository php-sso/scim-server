<?php

declare(strict_types=1);

namespace PhpSso\ScimServer;

use PhpSso\ScimServer\Contract\Accounts;
use PhpSso\ScimServer\Contract\ManagedUsers;

/**
 * What provisioning clients may do to people (users only, for now). A client manages the people it created, within the email
 * domains of its token, and never an administrator. Deactivating locks the account and ends its sessions, so the person is out
 * at once and can come back when the client reactivates them. What a delete does is the client's setting.
 */
final class ScimService
{
    public const USER_SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:User';

    public function __construct(
        private ManagedUsers $managed,
        private Accounts $accounts,
        private string $baseUrl,
    ) {
    }

    /**
     * @param array<mixed> $payload
     * @return ScimUser the managed person
     * @throws ScimException
     */
    public function create(ProvisioningClient $client, array $payload): ScimUser
    {
        $p = $this->profile($payload);
        $this->assertDomain($client, $p['email']);

        if ($this->managed->findByUserName($client->id, $p['userName']) !== null) {
            throw new ScimException(409, 'That userName already exists.', 'uniqueness');
        }
        if ($p['externalId'] !== null && $this->managed->findByExternalId($client->id, $p['externalId']) !== null) {
            throw new ScimException(409, 'That externalId already exists.', 'uniqueness');
        }

        $existing = $this->accounts->findByEmail($p['email']);
        if ($existing !== null && !$this->managed->isManaged($existing->sub) && !$existing->isAdmin && $client->adoptExisting) {
            $sub = $existing->sub; // an account made some other way, which this client may take over
            $this->accounts->updateProfile($sub, $p['name'], $p['email']);
        } else {
            if (($refused = $this->accounts->emailPolicyError($p['email'])) !== null) {
                throw new ScimException(400, $refused, 'invalidValue');
            }
            if (($taken = $this->accounts->emailError($p['email'], null)) !== null) {
                throw new ScimException(409, $taken, 'uniqueness');
            }
            $sub = $this->accounts->create($p['name'], $p['email']);
        }
        try {
            $this->managed->attach($client->id, $sub, $p['userName'], $p['externalId'], $p['givenName'], $p['familyName']);
        } catch (UniquenessViolation) {
            throw new ScimException(409, 'That userName or externalId already exists.', 'uniqueness');
        }
        $this->accounts->setActive($sub, $p['active'] ?? true);

        return $this->get($client, $sub);
    }

    /** @throws ScimException */
    public function get(ProvisioningClient $client, string $sub): ScimUser
    {
        return $this->managed->find($client->id, $sub) ?? throw new ScimException(404, 'No such user.');
    }

    /**
     * @param array<mixed> $payload the whole new representation
     * @throws ScimException
     */
    public function replace(ProvisioningClient $client, string $sub, array $payload): ScimUser
    {
        $user = $this->managedUser($client, $sub);
        $p = $this->profile($payload);
        $this->save($client, $user, $p['userName'], $p['externalId'], $p['email'], $p['name'], $p['givenName'], $p['familyName']);
        $this->accounts->setActive($sub, $p['active'] ?? true);

        return $this->get($client, $sub);
    }

    /**
     * @param list<mixed> $operations
     * @return array{user: ScimUser, ignored: list<string>}
     * @throws ScimException
     */
    public function patch(ProvisioningClient $client, string $sub, array $operations): array
    {
        $user = $this->managedUser($client, $sub);
        $read = ScimPatch::read($operations);
        $c = $read['changes'];

        $given = $this->change($c, 'givenName', $user->givenName);
        $family = $this->change($c, 'familyName', $user->familyName);
        $formatted = $this->change($c, 'name', null);
        $display = $this->change($c, 'displayName', null);
        $name = $user->name;
        if ($formatted !== null && $formatted !== '') {
            $name = $formatted;
        } elseif ($display !== null && $display !== '') {
            $name = $display;
        } elseif (array_key_exists('givenName', $c) || array_key_exists('familyName', $c)) {
            $composed = trim(($given ?? '') . ' ' . ($family ?? ''));
            $name = $composed !== '' ? $composed : $name;
        }
        $externalId = $this->change($c, 'externalId', $user->externalId);

        $this->save(
            $client,
            $user,
            $this->change($c, 'userName', $user->userName) ?? $user->userName,
            $externalId === '' ? null : $externalId,
            $this->change($c, 'email', $user->email) ?? $user->email,
            $name,
            $given === '' ? null : $given,
            $family === '' ? null : $family,
        );
        if (array_key_exists('active', $c)) {
            $this->accounts->setActive($sub, (bool) $c['active']);
        }

        return ['user' => $this->get($client, $sub), 'ignored' => $read['ignored']];
    }

    /**
     * What the client's setting says: deactivate (the default) or delete the account.
     * @throws ScimException
     */
    public function delete(ProvisioningClient $client, string $sub): void
    {
        $this->managedUser($client, $sub);
        if ($client->onDelete === OnDelete::Delete) {
            $this->accounts->delete($sub);

            return;
        }
        $this->accounts->setActive($sub, false);
    }

    /** @return array<string, mixed> the SCIM representation */
    public function representation(ScimUser $user): array
    {
        $name = ['formatted' => $user->name];
        if ($user->givenName !== null) {
            $name['givenName'] = $user->givenName;
        }
        if ($user->familyName !== null) {
            $name['familyName'] = $user->familyName;
        }

        return array_filter([
            'schemas' => [self::USER_SCHEMA],
            'id' => $user->sub,
            'externalId' => $user->externalId,
            'userName' => $user->userName,
            'name' => $name,
            'displayName' => $user->name,
            'emails' => [['value' => $user->email, 'type' => 'work', 'primary' => true]],
            'active' => $user->active,
            'meta' => ['resourceType' => 'User', 'location' => rtrim($this->baseUrl, '/') . '/scim/v2/Users/' . $user->sub],
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * The person if this client manages them, and they are not an administrator.
     * @throws ScimException
     */
    private function managedUser(ProvisioningClient $client, string $sub): ScimUser
    {
        $user = $this->get($client, $sub);
        if ($user->isAdmin) {
            throw new ScimException(403, 'Administrators are not managed through SCIM.');
        }

        return $user;
    }

    /**
     * Stores the new values.
     * @throws ScimException
     */
    private function save(ProvisioningClient $client, ScimUser $user, string $userName, ?string $externalId, string $email, string $name, ?string $givenName, ?string $familyName): void
    {
        $email = mb_strtolower($email);
        if ($userName === '' || mb_strlen($userName) > 255) {
            throw new ScimException(400, 'userName is required.', 'invalidValue');
        }
        $this->assertEmail($email);
        $this->assertDomain($client, $email);
        $other = $this->managed->findByUserName($client->id, $userName);
        if ($other !== null && $other->sub !== $user->sub) {
            throw new ScimException(409, 'That userName already exists.', 'uniqueness');
        }
        $other = $externalId === null ? null : $this->managed->findByExternalId($client->id, $externalId);
        if ($other !== null && $other->sub !== $user->sub) {
            throw new ScimException(409, 'That externalId already exists.', 'uniqueness');
        }
        if (strcasecmp($email, $user->email) !== 0) {
            if (($refused = $this->accounts->emailPolicyError($email)) !== null) {
                throw new ScimException(400, $refused, 'invalidValue');
            }
            if (($taken = $this->accounts->emailError($email, $user->sub)) !== null) {
                throw new ScimException(409, $taken, 'uniqueness');
            }
        }

        $this->accounts->updateProfile($user->sub, mb_substr($name, 0, 100), $email);
        try {
            $this->managed->update($user->sub, $userName, $externalId, $givenName, $familyName);
        } catch (UniquenessViolation) {
            throw new ScimException(409, 'That userName or externalId already exists.', 'uniqueness');
        }
    }

    /**
     * @param array<mixed> $payload
     * @return array{userName: string, externalId: ?string, email: string, name: string, givenName: ?string, familyName: ?string, active: ?bool}
     * @throws ScimException
     */
    private function profile(array $payload): array
    {
        $userName = is_string($payload['userName'] ?? null) ? trim($payload['userName']) : '';
        if ($userName === '' || mb_strlen($userName) > 255) {
            throw new ScimException(400, 'userName is required.', 'invalidValue');
        }
        $email = ScimPatch::emailFrom($payload['emails'] ?? null) ?? (str_contains($userName, '@') ? $userName : null);
        if ($email === null) {
            throw new ScimException(400, 'An email address is required: send it in "emails".', 'invalidValue');
        }
        $this->assertEmail($email);

        $nameData = is_array($payload['name'] ?? null) ? $payload['name'] : [];
        $given = $this->text($nameData['givenName'] ?? null);
        $family = $this->text($nameData['familyName'] ?? null);
        $name = $this->text($nameData['formatted'] ?? null) ?? $this->text($payload['displayName'] ?? null) ?? ($given !== null || $family !== null ? trim(($given ?? '') . ' ' . ($family ?? '')) : null) ?? (string) strstr($email, '@', true);

        return [
            'userName' => $userName,
            'externalId' => $this->text($payload['externalId'] ?? null),
            'email' => mb_strtolower($email),
            'name' => mb_substr($name, 0, 100),
            'givenName' => $given,
            'familyName' => $family,
            'active' => array_key_exists('active', $payload) ? ScimPatch::boolean($payload['active']) : null,
        ];
    }

    /**
     * What a PATCH set for this field: the new text (or null when it removed it), or `$current` when it did not name it.
     *
     * @param array<string, mixed> $changes
     */
    private function change(array $changes, string $key, ?string $current): ?string
    {
        if (!array_key_exists($key, $changes)) {
            return $current;
        }

        return is_string($changes[$key]) ? $changes[$key] : null;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @throws ScimException */
    private function assertEmail(string $email): void
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 255) {
            throw new ScimException(400, 'That is not a valid email address.', 'invalidValue');
        }
    }

    /**
     * A client may only manage addresses at the domains of its token.
     * @throws ScimException
     */
    private function assertDomain(ProvisioningClient $client, string $email): void
    {
        $domain = strtolower(substr($email, (int) strrpos($email, '@') + 1));
        if (!in_array($domain, $client->domains, true)) {
            throw new ScimException(400, 'That email address is outside the domains this token may manage.', 'invalidValue');
        }
    }
}
