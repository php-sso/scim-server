<?php

declare(strict_types=1);

namespace PhpSso\ScimServer\Tests\Support;

use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use PhpSso\ScimServer\OnDelete;
use PhpSso\ScimServer\ProvisioningClient;
use PhpSso\ScimServer\ScimController;
use PhpSso\ScimServer\ScimGroupService;
use PhpSso\ScimServer\ScimService;
use Psr\Http\Message\ServerRequestInterface;

/** A provisioning client for acme.com, with the service and controller built on in-memory ports. */
final class Support
{
    public readonly World $world;
    public readonly InMemoryAccounts $accounts;
    public readonly InMemoryManagedUsers $users;
    public readonly InMemoryGroupDirectory $directory;
    public readonly InMemoryManagedGroups $groups;
    public readonly InMemoryClients $clients;
    public readonly InMemoryEventLog $events;
    public readonly ScimService $service;
    public readonly ScimGroupService $groupService;
    public readonly ScimController $controller;
    public readonly ProvisioningClient $client;

    /**
     * @param list<string> $domains the domains the client may manage
     * @param list<string> $allowedDomains the domains the application accepts for new accounts (empty: all)
     */
    public function __construct(
        OnDelete $onDelete = OnDelete::Deactivate,
        bool $adopt = false,
        array $domains = ['acme.com'],
        string $groupPrefix = '',
        bool $adoptGroups = false,
        array $allowedDomains = [],
    ) {
        $this->world = new World();
        $this->accounts = new InMemoryAccounts($this->world);
        $this->accounts->allowedDomains = $allowedDomains;
        $this->users = new InMemoryManagedUsers($this->world);
        $this->directory = new InMemoryGroupDirectory($this->world);
        $this->groups = new InMemoryManagedGroups($this->world);
        $this->clients = new InMemoryClients();
        $this->events = new InMemoryEventLog();
        $this->service = new ScimService($this->users, $this->accounts, 'https://sso.test');
        $this->groupService = new ScimGroupService($this->directory, $this->groups, $this->users, $this->accounts, 'https://sso.test');
        $this->controller = new ScimController($this->clients, $this->service, $this->groupService, $this->users, $this->groups, $this->events, new ResponseFactory(), new StreamFactory(), 'https://sso.test');
        $this->client = $this->clients->add('Acme Okta', $domains, $onDelete, $adopt, $groupPrefix, $adoptGroups);
    }

    /** Someone the client does not manage: an account made some other way. @return string their sub */
    public function stranger(string $email = 'stranger@acme.com'): string
    {
        return $this->accounts->add('Stranger', $email);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function payload(string $userName = 'alice@acme.com', array $extra = []): array
    {
        return $extra + ['schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'], 'userName' => $userName, 'externalId' => 'ext-' . $userName, 'name' => ['givenName' => 'Alice', 'familyName' => 'Acme'], 'emails' => [['value' => $userName, 'primary' => true]], 'active' => true];
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $query
     * @param string|null $token '' for this client's own token, null for none
     */
    public function request(string $method, string $path, ?array $body = null, ?string $token = '', array $query = []): ServerRequestInterface
    {
        $request = (new ServerRequest([], [], 'https://sso.test/scim/v2' . $path, $method, 'php://memory'))->withQueryParams($query);
        $token = $token === '' ? $this->clients->tokenOf($this->client) : $token;
        if ($token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }
        if ($body !== null) {
            $request->getBody()->write((string) json_encode($body));
            $request->getBody()->rewind();
        }

        return $request;
    }
}
