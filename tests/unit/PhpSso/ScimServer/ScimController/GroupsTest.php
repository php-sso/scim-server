<?php

declare(strict_types=1);

namespace PhpSso\ScimServer\Tests\Unit\ScimController;

use PhpSso\ScimServer\ScimController;
use PhpSso\ScimServer\Tests\Support\Support;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

#[CoversMethod(ScimController::class, 'handle')]
final class GroupsTest extends TestCase
{
    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    private function idOf(ResponseInterface $response): string
    {
        $id = $this->json($response)['id'];
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $list a ListResponse
     * @return list<mixed>
     */
    private function resources(array $list): array
    {
        self::assertIsArray($list['Resources']);

        return array_values($list['Resources']);
    }

    public function testACreatedGroupComesBackWith201AndItsMembers(): void
    {
        // Arrange
        $app = new Support();
        $alice = $this->idOf($app->controller->handle($app->request('POST', '/Users', Support::payload('alice@acme.com'))));

        // Act
        $response = $app->controller->handle($app->request('POST', '/Groups', ['displayName' => 'Engineering', 'externalId' => 'g1', 'members' => [['value' => $alice]]]));

        // Assert
        $body = $this->json($response);
        self::assertSame(201, $response->getStatusCode());
        self::assertIsString($body['id']);
        self::assertSame('https://sso.test/scim/v2/Groups/' . $body['id'], $response->getHeaderLine('Location'));
        self::assertSame([['value' => $alice, 'display' => 'Alice Acme', 'type' => 'User', '$ref' => 'https://sso.test/scim/v2/Users/' . $alice]], $body['members']);
    }

    public function testFilteringByDisplayNameFindsTheGroup(): void
    {
        // Arrange
        $app = new Support();
        $app->controller->handle($app->request('POST', '/Groups', ['displayName' => 'Engineering']));
        $app->controller->handle($app->request('POST', '/Groups', ['displayName' => 'Sales']));

        // Act
        $found = $this->json($app->controller->handle($app->request('GET', '/Groups', null, '', ['filter' => 'displayName eq "ENGINEERING"'])));
        $none = $this->json($app->controller->handle($app->request('GET', '/Groups', null, '', ['filter' => 'displayName eq "Nope"'])));

        // Assert
        self::assertSame(1, $found['totalResults']);
        self::assertSame(['Engineering'], array_column($this->resources($found), 'displayName'));
        self::assertSame(0, $none['totalResults']);
    }

    public function testTheListOnlyShowsThisClientsGroups(): void
    {
        // Arrange
        $app = new Support();
        $app->controller->handle($app->request('POST', '/Groups', ['displayName' => 'Engineering']));
        $app->directory->add('Mine');

        // Act
        $list = $this->json($app->controller->handle($app->request('GET', '/Groups')));

        // Assert
        self::assertSame(1, $list['totalResults']);
        self::assertSame(['Engineering'], array_column($this->resources($list), 'displayName'));
    }

    public function testPatchAddsAMemberAndDeleteReturns204(): void
    {
        // Arrange
        $app = new Support();
        $alice = $this->idOf($app->controller->handle($app->request('POST', '/Users', Support::payload('alice@acme.com'))));
        $group = $this->idOf($app->controller->handle($app->request('POST', '/Groups', ['displayName' => 'Engineering'])));

        // Act
        $patched = $app->controller->handle($app->request('PATCH', '/Groups/' . $group, ['Operations' => [['op' => 'Add', 'path' => 'members', 'value' => [['value' => $alice]]]]]));
        $deleted = $app->controller->handle($app->request('DELETE', '/Groups/' . $group));

        // Assert
        $members = $this->json($patched)['members'];
        self::assertIsArray($members);
        self::assertSame([$alice], array_column($members, 'value'));
        self::assertSame(204, $deleted->getStatusCode());
        self::assertSame(404, $app->controller->handle($app->request('GET', '/Groups/' . $group))->getStatusCode());
    }

    public function testPutReplacesTheGroup(): void
    {
        // Arrange
        $app = new Support();
        $group = $this->idOf($app->controller->handle($app->request('POST', '/Groups', ['displayName' => 'Engineering'])));

        // Act
        $response = $app->controller->handle($app->request('PUT', '/Groups/' . $group, ['displayName' => 'Platform', 'externalId' => 'p1']));

        // Assert
        $body = $this->json($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['Platform', 'p1'], [$body['displayName'], $body['externalId']]);
    }

    public function testAGroupOfYoursIsNotFoundByItsId(): void
    {
        // Arrange
        $app = new Support();
        $mine = $app->directory->add('Staff');

        // Act
        $response = $app->controller->handle($app->request('GET', '/Groups/' . $mine));

        // Assert
        self::assertSame(404, $response->getStatusCode());
    }

    public function testANameClashIsA409(): void
    {
        // Arrange
        $app = new Support();
        $app->directory->add('Admins', 'Mine');

        // Act
        $response = $app->controller->handle($app->request('POST', '/Groups', ['displayName' => 'Admins']));

        // Assert
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('uniqueness', $this->json($response)['scimType']);
    }

    public function testAFilterOnSomethingElseIsRefusedForGroups(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $response = $app->controller->handle($app->request('GET', '/Groups', null, '', ['filter' => 'userName eq "x"']));

        // Assert
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalidFilter', $this->json($response)['scimType']);
    }

    public function testTheDiscoveryEndpointsListGroupsToo(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $types = $this->json($app->controller->handle($app->request('GET', '/ResourceTypes')));
        $schemas = $this->json($app->controller->handle($app->request('GET', '/Schemas')));

        // Assert
        self::assertSame(['/Users', '/Groups'], array_column($this->resources($types), 'endpoint'));
        self::assertContains('urn:ietf:params:scim:schemas:core:2.0:Group', array_column($this->resources($schemas), 'id'));
    }

    public function testGroupRequestsAreRecordedToo(): void
    {
        // Arrange
        $app = new Support();
        $app->controller->handle($app->request('POST', '/Groups', ['displayName' => 'Engineering']));

        // Act
        $events = $app->events->recent($app->client->id);

        // Assert
        self::assertSame([201], array_column($events, 'status'));
        self::assertSame('Created group Engineering', $events[0]['detail']);
    }
}
