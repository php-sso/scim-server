<?php

declare(strict_types=1);

namespace PhpSso\ScimServer\Tests\Unit\ScimBulk;

use PhpSso\ScimServer\ScimBulk;
use PhpSso\ScimServer\ScimException;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(ScimBulk::class, 'run')]
final class RunTest extends TestCase
{
    /** @var list<array{string, string, array<mixed>}> what the fake did, in order */
    private array $calls = [];
    private int $counter = 0;

    /**
     * A fake that creates things with ids u1, u2, ... and refuses what the test says to refuse.
     *
     * @param list<string> $refuse paths (or "POST /Users" with data userName) that fail with a 409
     */
    private function bulk(array $refuse = []): ScimBulk
    {
        return new ScimBulk(function (string $method, string $path, array $data) use ($refuse): array {
            $this->calls[] = [$method, $path, $data];
            if (in_array($path, $refuse, true) || ($method === 'POST' && is_string($data['userName'] ?? null) && in_array($data['userName'], $refuse, true))) {
                throw new ScimException(409, 'That already exists.', 'uniqueness');
            }
            if ($method === 'POST') {
                $id = 'u' . ++$this->counter;

                return ['status' => 201, 'data' => ['id' => $id], 'location' => 'https://sso.test/scim/v2' . $path . '/' . $id];
            }

            return ['status' => $method === 'DELETE' ? 204 : 200, 'data' => null, 'location' => null];
        }, 'https://sso.test/scim/v2');
    }

    /**
     * @param list<array<string, mixed>> $operations
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function request(array $operations, array $extra = []): array
    {
        return $extra + ['schemas' => [ScimBulk::REQUEST_SCHEMA], 'Operations' => $operations];
    }

    /**
     * @param array<string, mixed> $response
     * @return list<array<string, mixed>>
     */
    private function operations(array $response): array
    {
        self::assertIsArray($response['Operations']);

        /** @var list<array<string, mixed>> $operations */
        $operations = $response['Operations'];

        return $operations;
    }

    public function testASuccessfulBulkAnswersEachOperationWithItsStatusAsTextAndALocation(): void
    {
        // Arrange
        $bulk = $this->bulk();
        $request = $this->request([
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'a', 'data' => ['userName' => 'a@acme.com']],
            ['method' => 'PUT', 'path' => '/Users/x1', 'data' => ['userName' => 'x@acme.com']],
            ['method' => 'DELETE', 'path' => '/Users/x2'],
        ]);

        // Act
        $response = $bulk->run($request);

        // Assert
        self::assertSame([ScimBulk::RESPONSE_SCHEMA], $response['schemas']);
        self::assertSame([
            ['method' => 'POST', 'bulkId' => 'a', 'location' => 'https://sso.test/scim/v2/Users/u1', 'status' => '201'],
            ['method' => 'PUT', 'location' => 'https://sso.test/scim/v2/Users/x1', 'status' => '200'],
            ['method' => 'DELETE', 'location' => 'https://sso.test/scim/v2/Users/x2', 'status' => '204'],
        ], $this->operations($response));
    }

    public function testAReferenceInTheDataBecomesTheIdOfWhatWasCreated(): void
    {
        // Arrange
        $bulk = $this->bulk();
        $request = $this->request([
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'alice', 'data' => ['userName' => 'alice@acme.com']],
            ['method' => 'POST', 'path' => '/Groups', 'bulkId' => 'team', 'data' => ['displayName' => 'Team', 'members' => [['value' => 'bulkId:alice']]]],
        ]);

        // Act
        $bulk->run($request);

        // Assert
        self::assertSame([['value' => 'u1']], $this->calls[1][2]['members']);
    }

    public function testAReferenceToALaterOperationWaitsForIt(): void
    {
        // Arrange
        $bulk = $this->bulk();
        $request = $this->request([
            ['method' => 'POST', 'path' => '/Groups', 'bulkId' => 'team', 'data' => ['displayName' => 'Team', 'members' => [['value' => 'bulkId:alice']]]],
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'alice', 'data' => ['userName' => 'alice@acme.com']],
        ]);

        // Act
        $response = $bulk->run($request);

        // Assert
        self::assertSame(['POST /Users', 'POST /Groups'], array_map(static fn (array $c): string => $c[0] . ' ' . $c[1], $this->calls));
        self::assertSame([['value' => 'u1']], $this->calls[1][2]['members']);
        self::assertSame(['team', 'alice'], array_column($this->operations($response), 'bulkId'), 'answered in the order sent');
    }

    public function testAReferenceInThePathIsReplacedToo(): void
    {
        // Arrange
        $bulk = $this->bulk();
        $request = $this->request([
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'alice', 'data' => ['userName' => 'alice@acme.com']],
            ['method' => 'PATCH', 'path' => '/Users/bulkId:alice', 'data' => ['Operations' => []]],
        ]);

        // Act
        $response = $bulk->run($request);

        // Assert
        self::assertSame('PATCH /Users/u1', $this->calls[1][0] . ' ' . $this->calls[1][1]);
        self::assertSame('https://sso.test/scim/v2/Users/u1', $this->operations($response)[1]['location']);
    }

    public function testOnlyAWholeValueThatIsAReferenceIsReplaced(): void
    {
        // Arrange
        $bulk = $this->bulk();
        $request = $this->request([
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'a', 'data' => ['userName' => 'a@acme.com']],
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'b', 'data' => ['userName' => 'b@acme.com', 'displayName' => 'see /bulkId:a and bulkId:a here']],
        ]);

        // Act
        $bulk->run($request);

        // Assert
        self::assertSame('see /bulkId:a and bulkId:a here', $this->calls[1][2]['displayName']);
    }

    public function testAReferenceToNothingFailsOnlyThatOperationWith400(): void
    {
        // Arrange
        $bulk = $this->bulk();
        $request = $this->request([
            ['method' => 'POST', 'path' => '/Groups', 'bulkId' => 'team', 'data' => ['members' => [['value' => 'bulkId:ghost']]]],
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'a', 'data' => ['userName' => 'a@acme.com']],
        ]);

        // Act
        $operations = $this->operations($bulk->run($request));

        // Assert
        self::assertSame(['400', '201'], array_column($operations, 'status'));
        self::assertStringContainsString('ghost', (string) json_encode($operations[0]['response']));
        self::assertCount(1, $this->calls);
    }

    public function testAnOperationWhoseDependencyFailedIsNotDoneAndSaysConflict(): void
    {
        // Arrange
        $bulk = $this->bulk(['alice@acme.com']);
        $request = $this->request([
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'alice', 'data' => ['userName' => 'alice@acme.com']],
            ['method' => 'POST', 'path' => '/Groups', 'bulkId' => 'team', 'data' => ['members' => [['value' => 'bulkId:alice']]]],
        ]);

        // Act
        $operations = $this->operations($bulk->run($request));

        // Assert
        self::assertSame(['409', '409'], array_column($operations, 'status'));
        self::assertCount(1, $this->calls, 'the group was not tried');
    }

    public function testACircleOfReferencesFailsWithConflictAndDoesNothing(): void
    {
        // Arrange
        $bulk = $this->bulk();
        $request = $this->request([
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'a', 'data' => ['manager' => 'bulkId:b']],
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'b', 'data' => ['manager' => 'bulkId:a']],
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'c', 'data' => ['userName' => 'c@acme.com']],
        ]);

        // Act
        $operations = $this->operations($bulk->run($request));

        // Assert
        self::assertSame(['409', '409', '201'], array_column($operations, 'status'));
        self::assertCount(1, $this->calls, 'only the one outside the circle ran');
    }

    public function testAnErrorIsReportedWithItsOwnResponseAndTheRestStillRuns(): void
    {
        // Arrange
        $bulk = $this->bulk(['dup@acme.com']);
        $request = $this->request([
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'a', 'data' => ['userName' => 'dup@acme.com']],
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'b', 'data' => ['userName' => 'b@acme.com']],
        ]);

        // Act
        $operations = $this->operations($bulk->run($request));

        // Assert
        self::assertSame(['409', '201'], array_column($operations, 'status'));
        self::assertSame(['schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'], 'status' => '409', 'scimType' => 'uniqueness', 'detail' => 'That already exists.'], $operations[0]['response']);
        self::assertArrayNotHasKey('response', $operations[1]);
    }

    public function testFailOnErrorsStopsAfterThatManyErrorsAndLeavesTheRestOutOfTheAnswer(): void
    {
        // Arrange
        $bulk = $this->bulk(['bad1@acme.com', 'bad2@acme.com']);
        $request = $this->request([
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => '1', 'data' => ['userName' => 'ok@acme.com']],
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => '2', 'data' => ['userName' => 'bad1@acme.com']],
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => '3', 'data' => ['userName' => 'bad2@acme.com']],
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => '4', 'data' => ['userName' => 'never@acme.com']],
        ], ['failOnErrors' => 2]);

        // Act
        $operations = $this->operations($bulk->run($request));

        // Assert
        self::assertSame(['201', '409', '409'], array_column($operations, 'status'));
        self::assertCount(3, $this->calls);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badOperations(): iterable
    {
        yield 'unknown method' => [['method' => 'GET', 'path' => '/Users'], 'POST, PUT, PATCH or DELETE'];
        yield 'no method' => [['path' => '/Users', 'bulkId' => 'a', 'data' => []], 'POST, PUT, PATCH or DELETE'];
        yield 'an unknown path' => [['method' => 'POST', 'path' => '/Other', 'bulkId' => 'a', 'data' => []], 'The path must be'];
        yield 'a POST with an id' => [['method' => 'POST', 'path' => '/Users/x', 'bulkId' => 'a', 'data' => []], 'without an id'];
        yield 'a PUT without an id' => [['method' => 'PUT', 'path' => '/Users', 'data' => []], 'needs the id'];
        yield 'a POST without a bulkId' => [['method' => 'POST', 'path' => '/Users', 'data' => []], 'needs a "bulkId"'];
        yield 'a PUT without data' => [['method' => 'PUT', 'path' => '/Users/x'], 'needs "data"'];
        yield 'a path with a deeper segment' => [['method' => 'DELETE', 'path' => '/Users/x/y'], 'The path must be'];
    }

    /** @param array<string, mixed> $operation */
    #[\PHPUnit\Framework\Attributes\DataProvider('badOperations')]
    public function testAMalformedOperationFailsWith400AndIsNotDone(array $operation, string $expected): void
    {
        // Arrange
        $bulk = $this->bulk();

        // Act
        $operations = $this->operations($bulk->run($this->request([$operation])));

        // Assert
        self::assertSame('400', $operations[0]['status']);
        self::assertIsArray($operations[0]['response']);
        self::assertIsString($operations[0]['response']['detail']);
        self::assertStringContainsString($expected, $operations[0]['response']['detail']);
        self::assertSame([], $this->calls);
    }

    public function testTheSameBulkIdTwiceFailsTheSecondOperation(): void
    {
        // Arrange
        $bulk = $this->bulk();
        $request = $this->request([
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'a', 'data' => ['userName' => 'a@acme.com']],
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'a', 'data' => ['userName' => 'b@acme.com']],
        ]);

        // Act
        $operations = $this->operations($bulk->run($request));

        // Assert
        self::assertSame(['201', '400'], array_column($operations, 'status'));
        self::assertCount(1, $this->calls);
    }

    public function testAnUnexpectedErrorIsA500WithoutItsMessage(): void
    {
        // Arrange
        $bulk = new ScimBulk(static fn (): array => throw new \RuntimeException('SQLSTATE secret detail'), 'https://sso.test/scim/v2');
        $request = $this->request([['method' => 'DELETE', 'path' => '/Users/x']]);

        $log = ini_set('error_log', '/dev/null');

        // Act
        $operations = $this->operations($bulk->run($request));
        ini_set('error_log', $log === false ? '' : $log);

        // Assert
        self::assertSame('500', $operations[0]['status']);
        self::assertStringNotContainsString('secret', (string) json_encode($operations));
    }

    /** @return iterable<string, array{array<string, mixed>, int, string}> */
    public static function badRequests(): iterable
    {
        yield 'no schema' => [['Operations' => [['method' => 'DELETE', 'path' => '/Users/x']]], 400, 'schemas'];
        yield 'the wrong schema' => [['schemas' => ['urn:other'], 'Operations' => [['method' => 'DELETE', 'path' => '/Users/x']]], 400, 'schemas'];
        yield 'no operations' => [['schemas' => [ScimBulk::REQUEST_SCHEMA]], 400, 'Operations'];
        yield 'an empty list' => [['schemas' => [ScimBulk::REQUEST_SCHEMA], 'Operations' => []], 400, 'at least one'];
        yield 'operations that are not a list' => [['schemas' => [ScimBulk::REQUEST_SCHEMA], 'Operations' => ['a' => 1]], 400, 'Operations'];
        yield 'failOnErrors of zero' => [['schemas' => [ScimBulk::REQUEST_SCHEMA], 'Operations' => [['method' => 'DELETE', 'path' => '/Users/x']], 'failOnErrors' => 0], 400, 'failOnErrors'];
        yield 'failOnErrors as text' => [['schemas' => [ScimBulk::REQUEST_SCHEMA], 'Operations' => [['method' => 'DELETE', 'path' => '/Users/x']], 'failOnErrors' => '2'], 400, 'failOnErrors'];
    }

    /** @param array<string, mixed> $request */
    #[\PHPUnit\Framework\Attributes\DataProvider('badRequests')]
    public function testARequestThatCannotBeUsedAtAllIsRefused(array $request, int $status, string $mention): void
    {
        // Arrange
        $bulk = $this->bulk();

        // Act
        $thrown = null;
        try {
            $bulk->run($request);
        } catch (ScimException $e) {
            $thrown = $e;
        }

        // Assert
        self::assertNotNull($thrown);
        self::assertSame($status, $thrown->status);
        self::assertStringContainsString($mention, $thrown->getMessage());
        self::assertSame([], $this->calls);
    }

    public function testMoreThanTheMaximumNumberOfOperationsIsTooLarge(): void
    {
        // Arrange
        $bulk = $this->bulk();
        $operations = array_fill(0, ScimBulk::MAX_OPERATIONS + 1, ['method' => 'DELETE', 'path' => '/Users/x']);

        // Act
        $thrown = null;
        try {
            $bulk->run($this->request($operations));
        } catch (ScimException $e) {
            $thrown = $e;
        }

        // Assert
        self::assertSame([413, 'tooMany'], [$thrown?->status, $thrown?->scimType]);
        self::assertSame([], $this->calls);
    }

    public function testTheMaximumNumberOfOperationsIsAccepted(): void
    {
        // Arrange
        $bulk = $this->bulk();
        $operations = array_fill(0, ScimBulk::MAX_OPERATIONS, ['method' => 'DELETE', 'path' => '/Users/x']);

        // Act
        $response = $bulk->run($this->request($operations));

        // Assert
        self::assertCount(ScimBulk::MAX_OPERATIONS, $this->operations($response));
    }
}
