<?php

declare(strict_types=1);

namespace PhpSso\ScimServer\Tests\Unit\ScimController;

use PhpSso\ScimServer\ScimBulk;
use PhpSso\ScimServer\ScimController;
use PhpSso\ScimServer\Tests\Support\Support;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

#[CoversMethod(ScimController::class, 'handle')]
final class BulkTest extends TestCase
{
    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    private function str(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /** @return array<string, mixed> */
    private function arr(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param list<array<string, mixed>> $operations
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function bulk(array $operations, array $extra = []): array
    {
        return $extra + ['schemas' => [ScimBulk::REQUEST_SCHEMA], 'Operations' => $operations];
    }

    /**
     * @param array<string, mixed> $response a BulkResponse
     * @return list<array<string, mixed>>
     */
    private function operations(array $response): array
    {
        self::assertIsArray($response['Operations']);

        /** @var list<array<string, mixed>> $operations */
        $operations = $response['Operations'];

        return $operations;
    }

    public function testAGroupAndItsMemberAreCreatedInOneRequestEvenWhenTheGroupComesFirst(): void
    {
        // Arrange
        $app = new Support();
        $request = $app->request('POST', '/Bulk', self::bulk([
            ['method' => 'POST', 'path' => '/Groups', 'bulkId' => 'team', 'data' => ['displayName' => 'Engineering', 'members' => [['value' => 'bulkId:alice']]]],
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'alice', 'data' => Support::payload('alice@acme.com')],
        ]));

        // Act
        $response = $app->controller->handle($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $operations = $this->operations($this->json($response));
        self::assertSame(['201', '201'], array_column($operations, 'status'));
        self::assertIsString($operations[0]['location']);
        $group = $this->json($app->controller->handle($app->request('GET', substr($operations[0]['location'], strlen('https://sso.test/scim/v2')))));
        self::assertIsArray($group['members']);
        self::assertCount(1, $group['members']);
        self::assertSame('Alice Acme', $this->arr($group['members'][0])['display'] ?? null);
    }

    public function testReplacePatchAndDeleteDoWhatTheSingleEndpointsDo(): void
    {
        // Arrange
        $app = new Support();
        $alice = $this->json($app->controller->handle($app->request('POST', '/Users', Support::payload('alice@acme.com'))));
        $bob = $this->json($app->controller->handle($app->request('POST', '/Users', Support::payload('bob@acme.com'))));
        $carol = $this->json($app->controller->handle($app->request('POST', '/Users', Support::payload('carol@acme.com'))));
        $request = $app->request('POST', '/Bulk', self::bulk([
            ['method' => 'PUT', 'path' => '/Users/' . $this->str($alice['id']), 'data' => Support::payload('alice@acme.com', ['name' => ['givenName' => 'Alicia', 'familyName' => 'Acme']])],
            ['method' => 'PATCH', 'path' => '/Users/' . $this->str($bob['id']), 'data' => ['schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'], 'Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]]]],
            ['method' => 'DELETE', 'path' => '/Users/' . $this->str($carol['id'])],
        ]));

        // Act
        $response = $app->controller->handle($request);

        // Assert
        self::assertSame(['200', '200', '204'], array_column($this->operations($this->json($response)), 'status'));
        self::assertSame('Alicia', $this->arr($this->json($app->controller->handle($app->request('GET', '/Users/' . $this->str($alice['id']))))['name'])['givenName'] ?? null);
        self::assertFalse($this->json($app->controller->handle($app->request('GET', '/Users/' . $this->str($bob['id']))))['active']);
        self::assertSame(false, $this->json($app->controller->handle($app->request('GET', '/Users/' . $this->str($carol['id']))))['active'] ?? null, 'deleting deactivates, as for a single delete');
    }

    public function testAnErrorInOneOperationDoesNotStopTheOthers(): void
    {
        // Arrange
        $app = new Support();
        $app->controller->handle($app->request('POST', '/Users', Support::payload('taken@acme.com')));
        $request = $app->request('POST', '/Bulk', self::bulk([
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'dup', 'data' => Support::payload('taken@acme.com')],
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'new', 'data' => Support::payload('new@acme.com')],
        ]));

        // Act
        $operations = $this->operations($this->json($app->controller->handle($request)));

        // Assert
        self::assertSame(['409', '201'], array_column($operations, 'status'));
        self::assertSame('uniqueness', $this->arr($operations[0]['response'])['scimType'] ?? null);
    }

    public function testABulkCannotTouchSomeoneTheClientDoesNotManage(): void
    {
        // Arrange
        $app = new Support();
        $stranger = $app->stranger();
        $request = $app->request('POST', '/Bulk', self::bulk([['method' => 'DELETE', 'path' => '/Users/' . $stranger]]));

        // Act
        $operations = $this->operations($this->json($app->controller->handle($request)));

        // Assert
        self::assertSame(['404'], array_column($operations, 'status'));
        self::assertSame(404, $app->controller->handle($app->request('GET', '/Users/' . $stranger))->getStatusCode(), 'still not visible to this client');
        self::assertTrue($app->world->accounts[$stranger]['active']);
    }

    public function testEveryOperationAndTheBulkItselfAreRecordedForTheAdministrator(): void
    {
        // Arrange
        $app = new Support();
        $request = $app->request('POST', '/Bulk', self::bulk([
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'a', 'data' => Support::payload('a@acme.com')],
            ['method' => 'DELETE', 'path' => '/Users/nobody'],
        ]));

        // Act
        $app->controller->handle($request);

        // Assert
        $events = array_reverse($app->events->recent($app->client->id));
        self::assertSame([['POST', '/Users', 201], ['DELETE', '/Users/nobody', 404], ['POST', '/Bulk', 200]], array_map(static fn (array $e): array => [$e['method'], $e['path'], $e['status']], $events));
        self::assertSame('Created a@acme.com', $events[0]['detail']);
        self::assertSame('2 bulk operations, 1 failed', $events[2]['detail']);
    }

    public function testFailOnErrorsStopsTheBulk(): void
    {
        // Arrange
        $app = new Support();
        $request = $app->request('POST', '/Bulk', self::bulk([
            ['method' => 'DELETE', 'path' => '/Users/none1'],
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'a', 'data' => Support::payload('a@acme.com')],
        ], ['failOnErrors' => 1]));

        // Act
        $operations = $this->operations($this->json($app->controller->handle($request)));

        // Assert
        self::assertSame(['404'], array_column($operations, 'status'));
        self::assertSame(0, $app->users->list($app->client->id, null, 1, 10)->total);
    }

    public function testTooManyOperationsAreRefusedWith413(): void
    {
        // Arrange
        $app = new Support();
        $operations = array_fill(0, ScimBulk::MAX_OPERATIONS + 1, ['method' => 'DELETE', 'path' => '/Users/x']);

        // Act
        $response = $app->controller->handle($app->request('POST', '/Bulk', self::bulk($operations)));

        // Assert
        self::assertSame(413, $response->getStatusCode());
        self::assertSame('tooMany', $this->json($response)['scimType']);
    }

    public function testABodyOverTheBulkLimitIsRefusedWith413(): void
    {
        // Arrange
        $app = new Support();
        $request = $app->request('POST', '/Bulk', self::bulk([['method' => 'POST', 'path' => '/Users', 'bulkId' => 'a', 'data' => ['userName' => str_repeat('x', ScimBulk::MAX_PAYLOAD)]]]));

        // Act
        $response = $app->controller->handle($request);

        // Assert
        self::assertSame(413, $response->getStatusCode());
    }

    public function testASingleRequestStillHasTheSmallLimit(): void
    {
        // Arrange
        $app = new Support();
        $request = $app->request('POST', '/Users', Support::payload('a@acme.com', ['displayName' => str_repeat('x', 300000)]));

        // Act
        $response = $app->controller->handle($request);

        // Assert
        self::assertSame(413, $response->getStatusCode());
    }

    public function testBulkNeedsTheTokenAndThePostMethod(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $without = $app->controller->handle($app->request('POST', '/Bulk', self::bulk([['method' => 'DELETE', 'path' => '/Users/x']]), null));
        $get = $app->controller->handle($app->request('GET', '/Bulk'));

        // Assert
        self::assertSame([401, 405], [$without->getStatusCode(), $get->getStatusCode()]);
    }

    public function testABodyThatIsNotABulkRequestIsA400(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $response = $app->controller->handle($app->request('POST', '/Bulk', ['Operations' => [['method' => 'DELETE', 'path' => '/Users/x']]]));

        // Assert
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalidSyntax', $this->json($response)['scimType']);
    }
}
