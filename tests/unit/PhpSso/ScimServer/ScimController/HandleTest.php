<?php

declare(strict_types=1);

namespace PhpSso\ScimServer\Tests\Unit\ScimController;

use PhpSso\ScimServer\ScimController;
use PhpSso\ScimServer\Tests\Support\Support;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

#[CoversMethod(ScimController::class, 'handle')]
final class HandleTest extends TestCase
{
    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    public function testWithoutAValidTokenNothingIsAnswered(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $none = $app->controller->handle($app->request('GET', '/Users', null, null));
        $wrong = $app->controller->handle($app->request('GET', '/Users', null, 'scim_wrong'));

        // Assert
        self::assertSame([401, 401], [$none->getStatusCode(), $wrong->getStatusCode()]);
        self::assertStringContainsString('Bearer', $none->getHeaderLine('WWW-Authenticate'));
        self::assertSame(['urn:ietf:params:scim:api:messages:2.0:Error'], $this->json($none)['schemas']);
        self::assertSame([], $app->events->recent($app->client->id));
    }

    public function testACreatedPersonComesBackWith201AndALocation(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $response = $app->controller->handle($app->request('POST', '/Users', Support::payload('alice@acme.com')));

        // Assert
        $body = $this->json($response);
        self::assertSame(201, $response->getStatusCode());
        self::assertStringContainsString('application/scim+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertIsString($body['id']);
        self::assertSame('https://sso.test/scim/v2/Users/' . $body['id'], $response->getHeaderLine('Location'));
        self::assertSame('alice@acme.com', $body['userName']);
    }

    public function testAConflictIsA409WithTheScimErrorType(): void
    {
        // Arrange
        $app = new Support();
        $app->controller->handle($app->request('POST', '/Users', Support::payload('alice@acme.com')));

        // Act
        $response = $app->controller->handle($app->request('POST', '/Users', Support::payload('alice@acme.com')));

        // Assert
        $body = $this->json($response);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame(['409', 'uniqueness'], [$body['status'], $body['scimType']]);
    }

    public function testFilteringByUserNameFindsThePerson(): void
    {
        // Arrange
        $app = new Support();
        $app->controller->handle($app->request('POST', '/Users', Support::payload('alice@acme.com')));
        $app->controller->handle($app->request('POST', '/Users', Support::payload('bob@acme.com')));

        // Act
        $found = $this->json($app->controller->handle($app->request('GET', '/Users', null, '', ['filter' => 'userName eq "ALICE@acme.com"'])));
        $missing = $this->json($app->controller->handle($app->request('GET', '/Users', null, '', ['filter' => 'userName eq "nobody@acme.com"'])));

        // Assert
        self::assertSame(1, $found['totalResults']);
        self::assertSame('alice@acme.com', $this->first($found)['userName']);
        self::assertSame([0, []], [$missing['totalResults'], $missing['Resources']]);
    }

    public function testFilteringByEmailAndExternalIdWorksToo(): void
    {
        // Arrange
        $app = new Support();
        $app->controller->handle($app->request('POST', '/Users', Support::payload('alice@acme.com')));

        // Act
        $byEmail = $this->json($app->controller->handle($app->request('GET', '/Users', null, '', ['filter' => 'emails[type eq "work"].value eq "alice@acme.com"'])));
        $byExternalId = $this->json($app->controller->handle($app->request('GET', '/Users', null, '', ['filter' => 'externalId eq "ext-alice@acme.com"'])));

        // Assert
        self::assertSame([1, 1], [$byEmail['totalResults'], $byExternalId['totalResults']]);
    }

    public function testListingIsPagedAndOnlyShowsThisClientsPeople(): void
    {
        // Arrange
        $app = new Support();
        foreach (['a', 'b', 'c'] as $name) {
            $app->controller->handle($app->request('POST', '/Users', Support::payload($name . '@acme.com')));
        }
        $app->stranger('someone-else@acme.com');

        // Act
        $page = $this->json($app->controller->handle($app->request('GET', '/Users', null, '', ['startIndex' => '2', 'count' => '1'])));

        // Assert
        self::assertSame([3, 2, 1], [$page['totalResults'], $page['startIndex'], $page['itemsPerPage']]);
        self::assertSame('b@acme.com', $this->first($page)['userName']);
    }

    public function testAPageSizeIsCappedAndNonsenseFallsBackToTheDefaults(): void
    {
        // Arrange
        $app = new Support();
        $app->controller->handle($app->request('POST', '/Users', Support::payload('alice@acme.com')));

        // Act
        $capped = $this->json($app->controller->handle($app->request('GET', '/Users', null, '', ['count' => '100000'])));
        $zero = $this->json($app->controller->handle($app->request('GET', '/Users', null, '', ['count' => '0', 'startIndex' => '-5'])));

        // Assert
        self::assertSame([1, 1], [$capped['totalResults'], $capped['startIndex']]);
        self::assertSame([1, 0, 1], [$zero['totalResults'], $zero['itemsPerPage'], $zero['startIndex']]);
    }

    public function testAFilterWeDoNotSupportIsRefusedClearly(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $response = $app->controller->handle($app->request('GET', '/Users', null, '', ['filter' => 'userName co "al"']));

        // Assert
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalidFilter', $this->json($response)['scimType']);
    }

    public function testPatchDeactivatesAndDeleteReturns204(): void
    {
        // Arrange
        $app = new Support();
        $id = $this->json($app->controller->handle($app->request('POST', '/Users', Support::payload('alice@acme.com'))))['id'];
        self::assertIsString($id);

        // Act
        $patched = $app->controller->handle($app->request('PATCH', '/Users/' . $id, ['schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'], 'Operations' => [['op' => 'Replace', 'path' => 'active', 'value' => 'False']]]));
        $deleted = $app->controller->handle($app->request('DELETE', '/Users/' . $id));

        // Assert
        self::assertFalse($this->json($patched)['active']);
        self::assertSame(204, $deleted->getStatusCode());
        self::assertSame('', (string) $deleted->getBody());
    }

    public function testAPatchWithoutOperationsIsA400(): void
    {
        // Arrange
        $app = new Support();
        $id = $this->json($app->controller->handle($app->request('POST', '/Users', Support::payload('alice@acme.com'))))['id'];
        self::assertIsString($id);

        // Act
        $response = $app->controller->handle($app->request('PATCH', '/Users/' . $id, ['schemas' => []]));

        // Assert
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalidSyntax', $this->json($response)['scimType']);
    }

    public function testAPersonThatIsNotThisClientsIsNotFound(): void
    {
        // Arrange
        $app = new Support();
        $stranger = $app->stranger();

        // Act
        $response = $app->controller->handle($app->request('GET', '/Users/' . $stranger));

        // Assert
        self::assertSame(404, $response->getStatusCode());
    }

    public function testAMethodTheEndpointDoesNotHaveIsA405(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $response = $app->controller->handle($app->request('PUT', '/Users', []));

        // Assert
        self::assertSame(405, $response->getStatusCode());
    }

    public function testTheDiscoveryEndpointsAnswer(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $config = $this->json($app->controller->handle($app->request('GET', '/ServiceProviderConfig')));
        $types = $this->json($app->controller->handle($app->request('GET', '/ResourceTypes')));
        $schemas = $this->json($app->controller->handle($app->request('GET', '/Schemas')));

        // Assert
        self::assertSame([true, true], [$this->section($config, 'patch')['supported'], $this->section($config, 'bulk')['supported']]);
        self::assertSame([100, 1048576], [$this->section($config, 'bulk')['maxOperations'], $this->section($config, 'bulk')['maxPayloadSize']]);
        self::assertSame('/Users', $this->first($types)['endpoint']);
        self::assertSame('urn:ietf:params:scim:schemas:core:2.0:User', $this->first($schemas)['id']);
    }

    public function testAnUnknownPathIsNotFound(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $response = $app->controller->handle($app->request('POST', '/Nothing', ['Operations' => []]));

        // Assert
        self::assertSame(404, $response->getStatusCode());
    }

    public function testABodyThatIsNotJsonIsA400(): void
    {
        // Arrange
        $app = new Support();
        $request = $app->request('POST', '/Users');
        $request->getBody()->write('not json');
        $request->getBody()->rewind();

        // Act
        $response = $app->controller->handle($request);

        // Assert
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalidSyntax', $this->json($response)['scimType']);
    }

    public function testABodyThatIsTooLargeIsA413(): void
    {
        // Arrange
        $app = new Support();
        $request = $app->request('POST', '/Users');
        $request->getBody()->write(str_repeat('x', 262145));
        $request->getBody()->rewind();

        // Act
        $response = $app->controller->handle($request);

        // Assert
        self::assertSame(413, $response->getStatusCode());
    }

    public function testEveryRequestIsRecordedForTheAdministrator(): void
    {
        // Arrange
        $app = new Support();
        $app->controller->handle($app->request('POST', '/Users', Support::payload('alice@acme.com')));
        $app->controller->handle($app->request('POST', '/Users', Support::payload('alice@acme.com')));

        // Act
        $events = $app->events->recent($app->client->id);

        // Assert
        self::assertSame([409, 201], array_column($events, 'status'));
        self::assertSame('Created alice@acme.com', $events[1]['detail']);
        self::assertStringContainsString('already exists', $events[0]['detail']);
        self::assertSame(['POST', '/Users'], [$events[0]['method'], $events[0]['path']]);
    }

    public function testASwitchedOffClientIsRefusedAtOnce(): void
    {
        // Arrange
        $app = new Support();
        $app->clients->switchOff($app->client);

        // Act
        $response = $app->controller->handle($app->request('GET', '/Users'));

        // Assert
        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * @param array<string, mixed> $list a ListResponse
     * @return array<string, mixed>
     */
    private function first(array $list): array
    {
        self::assertIsArray($list['Resources']);
        self::assertIsArray($list['Resources'][0]);

        /** @var array<string, mixed> $first */
        $first = $list['Resources'][0];

        return $first;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function section(array $data, string $key): array
    {
        self::assertIsArray($data[$key]);

        /** @var array<string, mixed> $section */
        $section = $data[$key];

        return $section;
    }
}
