<?php

declare(strict_types=1);

namespace PhpSso\ScimServer\Contract;

use PhpSso\ScimServer\Account;

/**
 * The application's own accounts. Everything about how an account is named, stored, locked or removed lives behind this
 * port: the SCIM server only says what should happen.
 */
interface Accounts
{
    public function findByEmail(string $email): ?Account;

    /** A reason this address cannot be used by the person `$exceptSub` because another account has it, or null when it is free. */
    public function emailError(string $email, ?string $exceptSub): ?string;

    /**
     * A reason the application does not accept an automatically provisioned account at this address (for example, its
     * domain is not allowed), or null. Asked for new accounts and for address changes, never for an account a client takes
     * over or for a change that keeps the address.
     */
    public function emailPolicyError(string $email): ?string;

    /**
     * Makes a person who can sign in but has no password and no administrator rights. The address counts as verified,
     * because the client is the authority for the domains of its token. Choosing a sign-in name is up to the application.
     *
     * @return string the new account's `sub`
     */
    public function create(string $name, string $email): string;

    /** Changes the name and address; the address counts as verified. */
    public function updateProfile(string $sub, string $name, string $email): void;

    /** Active people can sign in. The others are locked out at once and everywhere: their sessions end. */
    public function setActive(string $sub, bool $active): void;

    /** Removes the account, ending its sessions first. */
    public function delete(string $sub): void;

    /** What to show for this person in a group's member list, or null when there is no such account. */
    public function displayName(string $sub): ?string;
}
