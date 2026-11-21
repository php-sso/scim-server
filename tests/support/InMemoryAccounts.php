<?php

declare(strict_types=1);

namespace PhpSso\ScimServer\Tests\Support;

use PhpSso\ScimServer\Account;
use PhpSso\ScimServer\Contract\Accounts;

final class InMemoryAccounts implements Accounts
{
    /** @var list<string> lower-case domains accepted for new accounts; empty accepts every domain */
    public array $allowedDomains = [];

    public function __construct(private World $world)
    {
    }

    /** Adds an account the way an administrator would, outside SCIM. @return string its sub */
    public function add(string $name, string $email, bool $admin = false): string
    {
        return $this->insert($name, $email, $admin);
    }

    public function exists(string $sub): bool
    {
        return isset($this->world->accounts[$sub]);
    }

    public function isActive(string $sub): bool
    {
        return $this->world->accounts[$sub]['active'] ?? false;
    }

    public function email(string $sub): ?string
    {
        return $this->world->accounts[$sub]['email'] ?? null;
    }

    public function makeAdmin(string $sub): void
    {
        $account = $this->world->accounts[$sub];
        $account['admin'] = true;
        $this->world->accounts[$sub] = $account;
    }

    public function sessionsEnded(string $sub): int
    {
        return $this->world->sessionsEnded[$sub] ?? 0;
    }

    public function findByEmail(string $email): ?Account
    {
        foreach ($this->world->accounts as $sub => $account) {
            if (strcasecmp($account['email'], $email) === 0) {
                return new Account($sub, $account['admin']);
            }
        }

        return null;
    }

    public function emailError(string $email, ?string $exceptSub): ?string
    {
        foreach ($this->world->accounts as $sub => $account) {
            if ($sub !== $exceptSub && strcasecmp($account['email'], $email) === 0) {
                return 'This email address is already in use.';
            }
        }

        return null;
    }

    public function emailPolicyError(string $email): ?string
    {
        $domain = strtolower(substr($email, (int) strrpos($email, '@') + 1));

        return $this->allowedDomains === [] || in_array($domain, $this->allowedDomains, true) ? null : 'Accounts for that email domain are not allowed here.';
    }

    public function create(string $name, string $email): string
    {
        return $this->insert($name, $email, false);
    }

    public function updateProfile(string $sub, string $name, string $email): void
    {
        $account = $this->world->accounts[$sub];
        $account['name'] = $name;
        $account['email'] = $email;
        $this->world->accounts[$sub] = $account;
    }

    public function setActive(string $sub, bool $active): void
    {
        $account = $this->world->accounts[$sub];
        $account['active'] = $active;
        $this->world->accounts[$sub] = $account;
        if (!$active) {
            $this->world->sessionsEnded[$sub] = $this->sessionsEnded($sub) + 1;
        }
    }

    public function delete(string $sub): void
    {
        $this->world->sessionsEnded[$sub] = $this->sessionsEnded($sub) + 1;
        $this->world->removeAccount($sub);
    }

    public function displayName(string $sub): ?string
    {
        return $this->world->accounts[$sub]['name'] ?? null;
    }

    private function insert(string $name, string $email, bool $admin): string
    {
        $sub = 'sub-' . ++$this->world->accountCounter;
        $this->world->accounts[$sub] = ['name' => $name, 'email' => $email, 'admin' => $admin, 'active' => true];

        return $sub;
    }
}
