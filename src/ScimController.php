<?php

declare(strict_types=1);

namespace PhpSso\ScimServer;

use PhpSso\ScimServer\Contract\ClientAuthenticator;
use PhpSso\ScimServer\Contract\EventLog;
use PhpSso\ScimServer\Contract\ManagedGroups;
use PhpSso\ScimServer\Contract\ManagedUsers;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The SCIM 2.0 endpoints (RFC 7643 and 7644) under `/scim/v2`: users only. These are called by a company's provisioning
 * system, not by a browser: there is no session, no cookie and no CSRF token. Every request carries a bearer token that
 * belongs to one SCIM client, and every request is recorded for the administrator.
 */
final class ScimController
{
    private const MAX_BODY = 262144;
    private const MAX_PAGE = 200;
    private const CONTENT_TYPE = 'application/scim+json; charset=utf-8';
    private const ERROR_SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:Error';

    public function __construct(
        private ClientAuthenticator $clients,
        private ScimService $service,
        private ScimGroupService $groupService,
        private ManagedUsers $managedUsers,
        private ManagedGroups $managedGroups,
        private EventLog $events,
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
        private string $baseUrl,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $client = $this->clients->authenticate($this->bearer($request));
        if ($client === null) {
            return $this->error(401, 'A valid bearer token is required.')->withHeader('WWW-Authenticate', 'Bearer realm="scim"');
        }
        $path = '/' . trim(substr($request->getUri()->getPath(), strlen('/scim/v2')), '/');
        $method = $request->getMethod();
        $detail = '';

        try {
            $response = $this->route($client, $method, $path, $request, $detail);
        } catch (ScimException $e) {
            $detail = $e->getMessage();
            $response = $this->error($e->status, $e->getMessage(), $e->scimType);
        } catch (\Throwable $e) {
            error_log('SSO SCIM: ' . $e->getMessage());
            $detail = 'Server error.';
            $response = $this->error(500, 'The server could not do that.');
        }
        $this->events->record($client->id, $method, $path, $response->getStatusCode(), $detail);

        return $response;
    }

    private function route(ProvisioningClient $client, string $method, string $path, ServerRequestInterface $request, string &$detail): ResponseInterface
    {
        if ($method === 'GET' && $path === '/ServiceProviderConfig') {
            return $this->json($this->serviceProviderConfig());
        }
        if ($method === 'GET' && $path === '/ResourceTypes') {
            return $this->json($this->listing([$this->resourceType('User', '/Users', ScimService::USER_SCHEMA), $this->resourceType('Group', '/Groups', ScimGroupService::GROUP_SCHEMA)]));
        }
        if ($method === 'GET' && $path === '/Schemas') {
            return $this->json($this->listing([$this->userSchema(), $this->groupSchema()]));
        }
        if ($method === 'GET' && $path === '/Users') {
            return $this->list($client, $request);
        }
        if ($method === 'GET' && preg_match('#^/Users/([^/]+)$#', $path, $m) === 1) {
            return $this->json($this->service->representation($this->service->get($client, rawurldecode($m[1]))));
        }
        if ($method === 'GET' && $path === '/Groups') {
            return $this->listGroups($client, $request);
        }
        if ($method === 'GET' && preg_match('#^/Groups/(\d+)$#', $path, $m) === 1) {
            return $this->json($this->groupService->representation($this->groupService->get($client, (int) $m[1])));
        }
        if ($path === '/Bulk') {
            if ($method !== 'POST') {
                throw new ScimException(405, 'Method not allowed.');
            }

            return $this->json($this->bulk($client, $this->body($request, ScimBulk::MAX_PAYLOAD), $detail));
        }

        if (preg_match('#^/(Users|Groups)(/[^/]+)?$#', $path) !== 1) {
            throw new ScimException(404, 'Not found. This server provides Users and Groups.');
        }
        $done = $this->mutate($client, $method, $path, in_array($method, ['POST', 'PUT', 'PATCH'], true) ? $this->body($request) : [], $detail);
        if ($done['data'] === null) {
            return $this->responses->createResponse($done['status']);
        }
        $response = $this->json($done['data'], $done['status']);

        return $done['location'] === null ? $response : $response->withHeader('Location', $done['location']);
    }

    /**
     * Creates, replaces, patches or deletes a user or a group: what one request does, and what each operation of a bulk
     * request does.
     *
     * @param array<mixed> $body
     * @return array{status: int, data: array<string, mixed>|null, location: string|null}
     * @throws ScimException
     */
    private function mutate(ProvisioningClient $client, string $method, string $path, array $body, string &$detail): array
    {
        $base = rtrim($this->baseUrl, '/') . '/scim/v2';
        if ($path === '/Users') {
            if ($method !== 'POST') {
                throw new ScimException(405, 'Method not allowed.');
            }
            $user = $this->service->create($client, $body);
            $detail = 'Created ' . $user->userName;

            return ['status' => 201, 'data' => $this->service->representation($user), 'location' => $base . '/Users/' . $user->sub];
        }
        if (preg_match('#^/Users/([^/]+)$#', $path, $m) === 1) {
            $sub = rawurldecode($m[1]);

            return match ($method) {
                'PUT' => ['status' => 200, 'data' => $this->service->representation($this->service->replace($client, $sub, $body)), 'location' => null],
                'PATCH' => $this->patchedUser($client, $sub, $body, $detail),
                'DELETE' => $this->deletedUser($client, $sub),
                default => throw new ScimException(405, 'Method not allowed.'),
            };
        }
        if ($path === '/Groups') {
            if ($method !== 'POST') {
                throw new ScimException(405, 'Method not allowed.');
            }
            $group = $this->groupService->create($client, $body);
            $detail = 'Created group ' . $group->displayName;

            return ['status' => 201, 'data' => $this->groupService->representation($group), 'location' => $base . '/Groups/' . $group->id];
        }
        if (preg_match('#^/Groups/(\d+)$#', $path, $m) === 1) {
            $id = (int) $m[1];

            return match ($method) {
                'PUT' => ['status' => 200, 'data' => $this->groupService->representation($this->groupService->replace($client, $id, $body)), 'location' => null],
                'PATCH' => $this->patchedGroup($client, $id, $body, $detail),
                'DELETE' => $this->deletedGroup($client, $id),
                default => throw new ScimException(405, 'Method not allowed.'),
            };
        }

        throw new ScimException(404, 'Not found. This server provides Users and Groups.');
    }

    private function list(ProvisioningClient $client, ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $filter = isset($query['filter']) && is_string($query['filter']) && trim($query['filter']) !== '' ? $this->parseFilter($query['filter']) : null;
        $start = max(1, $this->number($query['startIndex'] ?? null, 1));
        $count = min(self::MAX_PAGE, max(0, $this->number($query['count'] ?? null, 100)));
        $page = $this->managedUsers->list($client->id, $filter, $start, $count);

        return $this->json([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
            'totalResults' => $page->total,
            'startIndex' => $start,
            'itemsPerPage' => count($page->users),
            'Resources' => array_map([$this->service, 'representation'], $page->users),
        ]);
    }

    private function listGroups(ProvisioningClient $client, ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $filter = isset($query['filter']) && is_string($query['filter']) && trim($query['filter']) !== '' ? $this->parseFilter($query['filter'], true) : null;
        $start = max(1, $this->number($query['startIndex'] ?? null, 1));
        $count = min(self::MAX_PAGE, max(0, $this->number($query['count'] ?? null, 100)));
        $page = $this->managedGroups->list($client->id, $filter, $start, $count);

        return $this->json([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
            'totalResults' => $page->total,
            'startIndex' => $start,
            'itemsPerPage' => count($page->groups),
            'Resources' => array_map([$this->groupService, 'representation'], $page->groups),
        ]);
    }

    /**
     * Only `attribute eq "value"`, which is what providers use to look a person up.
     * @throws ScimException
     */
    private function parseFilter(string $filter, bool $groups = false): ScimFilter
    {
        if (preg_match('/^\s*([A-Za-z.\[\]" ]+?)\s+eq\s+"((?:[^"\\\\]|\\\\.)*)"\s*$/i', $filter, $m) !== 1) {
            throw new ScimException(400, 'Only filters of the form: attribute eq "value" are supported.', 'invalidFilter');
        }
        $attribute = strtolower(preg_replace('/\s+/', '', $m[1]) ?? '');
        $attr = match (true) {
            $groups && $attribute === 'displayname' => 'displayName',
            $groups && ($attribute === 'externalid' || $attribute === 'id') => $attribute === 'id' ? 'id' : 'externalId',
            $groups => throw new ScimException(400, "Filtering on \"{$m[1]}\" is not supported for groups. Use displayName, externalId or id.", 'invalidFilter'),
            $attribute === 'username' => 'userName',
            $attribute === 'externalid' => 'externalId',
            $attribute === 'id' => 'id',
            $attribute === 'emails.value', $attribute === 'emails', str_starts_with($attribute, 'emails[') => 'email',
            default => throw new ScimException(400, "Filtering on \"{$m[1]}\" is not supported. Use userName, externalId, id or emails.value.", 'invalidFilter'),
        };

        return new ScimFilter($attr, stripcslashes($m[2]));
    }

    /**
     * @param array<mixed> $body
     * @return array{status: int, data: array<string, mixed>|null, location: string|null}
     */
    private function patchedUser(ProvisioningClient $client, string $sub, array $body, string &$detail): array
    {
        $result = $this->service->patch($client, $sub, $this->operations($body));
        if ($result['ignored'] !== []) {
            $detail = 'Ignored: ' . implode(', ', $result['ignored']);
        }

        return ['status' => 200, 'data' => $this->service->representation($result['user']), 'location' => null];
    }

    /** @return array{status: int, data: null, location: null} */
    private function deletedUser(ProvisioningClient $client, string $sub): array
    {
        $this->service->delete($client, $sub);

        return ['status' => 204, 'data' => null, 'location' => null];
    }

    /**
     * @param array<mixed> $body
     * @return array{status: int, data: array<string, mixed>|null, location: string|null}
     */
    private function patchedGroup(ProvisioningClient $client, int $id, array $body, string &$detail): array
    {
        $result = $this->groupService->patch($client, $id, $this->operations($body));
        if ($result['ignored'] !== []) {
            $detail = 'Ignored: ' . implode(', ', $result['ignored']);
        }

        return ['status' => 200, 'data' => $this->groupService->representation($result['group']), 'location' => null];
    }

    /** @return array{status: int, data: null, location: null} */
    private function deletedGroup(ProvisioningClient $client, int $id): array
    {
        $this->groupService->delete($client, $id);

        return ['status' => 204, 'data' => null, 'location' => null];
    }

    /**
     * @param array<mixed> $body
     * @return list<mixed>
     * @throws ScimException
     */
    private function operations(array $body): array
    {
        $operations = $body['Operations'] ?? $body['operations'] ?? null;
        if (!is_array($operations)) {
            throw new ScimException(400, 'A PATCH needs "Operations".', 'invalidSyntax');
        }

        return array_values($operations);
    }

    /**
     * Does a bulk request (RFC 7644 section 3.7). Each operation is recorded for the administrator as if it had been sent on
     * its own, and so is the bulk request itself.
     *
     * @param array<mixed> $body
     * @return array<string, mixed>
     */
    private function bulk(ProvisioningClient $client, array $body, string &$detail): array
    {
        $execute = function (string $method, string $path, array $data) use ($client): array {
            $note = '';
            try {
                $done = $this->mutate($client, $method, $path, $data, $note);
            } catch (ScimException $e) {
                $this->events->record($client->id, $method, $path, $e->status, $e->getMessage());

                throw $e;
            } catch (\Throwable $e) {
                $this->events->record($client->id, $method, $path, 500, 'Server error.');

                throw $e;
            }
            $this->events->record($client->id, $method, $path, $done['status'], $note);

            return $done;
        };
        $response = (new ScimBulk($execute, rtrim($this->baseUrl, '/') . '/scim/v2'))->run($body);
        $operations = is_array($response['Operations'] ?? null) ? $response['Operations'] : [];
        $failed = count(array_filter($operations, static fn (mixed $op): bool => is_array($op) && is_string($op['status'] ?? null) && (int) $op['status'] >= 400));
        $detail = count($operations) . ' bulk operation' . (count($operations) === 1 ? '' : 's') . ($failed > 0 ? ", {$failed} failed" : '');

        return $response;
    }

    /**
     * @return array<mixed>
     * @throws ScimException
     */
    private function body(ServerRequestInterface $request, int $max = self::MAX_BODY): array
    {
        $raw = (string) $request->getBody();
        if (strlen($raw) > $max) {
            throw new ScimException(413, 'That request is too large.');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new ScimException(400, 'The body is not a JSON object.', 'invalidSyntax');
        }

        return $data;
    }

    /** A paging parameter: the number in the text, or the default when it is missing or not text. */
    private function number(mixed $value, int $default): int
    {
        return is_string($value) ? (int) $value : $default;
    }

    private function bearer(ServerRequestInterface $request): string
    {
        return preg_match('/^Bearer\s+(\S+)$/i', $request->getHeaderLine('Authorization'), $m) === 1 ? $m[1] : '';
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $status = 200): ResponseInterface
    {
        $body = $this->streams->createStream(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $this->responses->createResponse($status)
            ->withHeader('Content-Type', self::CONTENT_TYPE)
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($body);
    }

    private function error(int $status, string $detail, ?string $scimType = null): ResponseInterface
    {
        return $this->json(array_filter(['schemas' => [self::ERROR_SCHEMA], 'status' => (string) $status, 'scimType' => $scimType, 'detail' => $detail], static fn (mixed $v): bool => $v !== null), $status);
    }

    /**
     * @param list<array<string, mixed>> $resources
     * @return array<string, mixed>
     */
    private function listing(array $resources): array
    {
        return ['schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'], 'totalResults' => count($resources), 'startIndex' => 1, 'itemsPerPage' => count($resources), 'Resources' => $resources];
    }

    /** @return array<string, mixed> */
    private function serviceProviderConfig(): array
    {
        return [
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig'],
            'patch' => ['supported' => true],
            'bulk' => ['supported' => true, 'maxOperations' => ScimBulk::MAX_OPERATIONS, 'maxPayloadSize' => ScimBulk::MAX_PAYLOAD],
            'filter' => ['supported' => true, 'maxResults' => self::MAX_PAGE],
            'changePassword' => ['supported' => false],
            'sort' => ['supported' => false],
            'etag' => ['supported' => false],
            'authenticationSchemes' => [['type' => 'oauthbearertoken', 'name' => 'Bearer token', 'description' => 'A token created in the admin panel.', 'primary' => true]],
            'meta' => ['resourceType' => 'ServiceProviderConfig', 'location' => rtrim($this->baseUrl, '/') . '/scim/v2/ServiceProviderConfig'],
        ];
    }

    /** @return array<string, mixed> */
    private function resourceType(string $name, string $endpoint, string $schema): array
    {
        return [
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ResourceType'],
            'id' => $name, 'name' => $name, 'endpoint' => $endpoint, 'schema' => $schema,
            'meta' => ['resourceType' => 'ResourceType', 'location' => rtrim($this->baseUrl, '/') . '/scim/v2/ResourceTypes/' . $name],
        ];
    }

    /** @return array<string, mixed> */
    private function groupSchema(): array
    {
        $attribute = static fn (string $name, string $type = 'string', bool $required = false): array => [
            'name' => $name, 'type' => $type, 'multiValued' => false, 'required' => $required, 'caseExact' => false, 'mutability' => 'readWrite', 'returned' => 'default', 'uniqueness' => 'none',
        ];

        return [
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:Schema'],
            'id' => ScimGroupService::GROUP_SCHEMA, 'name' => 'Group', 'description' => 'A group of people.',
            'attributes' => [
                $attribute('displayName', 'string', true), $attribute('externalId'),
                ['name' => 'members', 'type' => 'complex', 'multiValued' => true, 'required' => false, 'mutability' => 'readWrite', 'returned' => 'default', 'subAttributes' => [$attribute('value', 'string', true), $attribute('display')]],
            ],
            'meta' => ['resourceType' => 'Schema', 'location' => rtrim($this->baseUrl, '/') . '/scim/v2/Schemas/' . ScimGroupService::GROUP_SCHEMA],
        ];
    }

    /** @return array<string, mixed> */
    private function userSchema(): array
    {
        $attribute = static fn (string $name, string $type = 'string', bool $required = false, string $mutability = 'readWrite'): array => [
            'name' => $name, 'type' => $type, 'multiValued' => false, 'required' => $required, 'caseExact' => false, 'mutability' => $mutability, 'returned' => 'default', 'uniqueness' => 'none',
        ];

        return [
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:Schema'],
            'id' => ScimService::USER_SCHEMA, 'name' => 'User', 'description' => 'A person who can sign in.',
            'attributes' => [
                $attribute('userName', 'string', true), $attribute('externalId'), $attribute('displayName'), $attribute('active', 'boolean'),
                ['name' => 'name', 'type' => 'complex', 'multiValued' => false, 'required' => false, 'mutability' => 'readWrite', 'returned' => 'default', 'subAttributes' => [$attribute('formatted'), $attribute('givenName'), $attribute('familyName')]],
                ['name' => 'emails', 'type' => 'complex', 'multiValued' => true, 'required' => true, 'mutability' => 'readWrite', 'returned' => 'default', 'subAttributes' => [$attribute('value', 'string', true), $attribute('type'), $attribute('primary', 'boolean')]],
            ],
            'meta' => ['resourceType' => 'Schema', 'location' => rtrim($this->baseUrl, '/') . '/scim/v2/Schemas/' . ScimService::USER_SCHEMA],
        ];
    }
}
