<?php

declare(strict_types=1);

namespace PhpSso\ScimServer\Tests\Unit\ScimPatch;

use PhpSso\ScimServer\ScimException;
use PhpSso\ScimServer\ScimPatch;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversMethod(ScimPatch::class, 'read')]
#[CoversMethod(ScimPatch::class, 'boolean')]
#[CoversMethod(ScimPatch::class, 'emailFrom')]
final class ReadTest extends TestCase
{
    /** @return iterable<string, array{list<array<string, mixed>>, array<string, mixed>}> */
    public static function shapes(): iterable
    {
        yield 'replace active with a path' => [[['op' => 'replace', 'path' => 'active', 'value' => false]], ['active' => false]];
        yield 'any case of the operation' => [[['op' => 'Replace', 'path' => 'active', 'value' => true]], ['active' => true]];
        yield 'active as text, the way Entra sends it' => [[['op' => 'Replace', 'path' => 'active', 'value' => 'False']], ['active' => false]];
        yield 'no path, an object' => [[['op' => 'replace', 'value' => ['active' => false, 'displayName' => 'Alice B']]], ['active' => false, 'displayName' => 'Alice B']];
        yield 'no path, a nested name' => [[['op' => 'replace', 'value' => ['name' => ['givenName' => 'Al', 'familyName' => 'B']]]], ['givenName' => 'Al', 'familyName' => 'B']];
        yield 'a dotted name path' => [[['op' => 'replace', 'path' => 'name.givenName', 'value' => 'Al']], ['givenName' => 'Al']];
        yield 'userName' => [[['op' => 'replace', 'path' => 'userName', 'value' => 'al@acme.com']], ['userName' => 'al@acme.com']];
        yield 'email by a filter' => [[['op' => 'replace', 'path' => 'emails[type eq "work"].value', 'value' => 'new@acme.com']], ['email' => 'new@acme.com']];
        yield 'email as a list' => [[['op' => 'add', 'path' => 'emails', 'value' => [['value' => 'a@acme.com'], ['value' => 'b@acme.com', 'primary' => true]]]], ['email' => 'b@acme.com']];
        yield 'externalId' => [[['op' => 'replace', 'path' => 'externalId', 'value' => 'x-9']], ['externalId' => 'x-9']];
        yield 'removing the externalId' => [[['op' => 'remove', 'path' => 'externalId']], ['externalId' => null]];
        yield 'the last of two changes to the same thing wins' => [[['op' => 'replace', 'path' => 'active', 'value' => false], ['op' => 'replace', 'path' => 'active', 'value' => true]], ['active' => true]];
    }

    /**
     * @param list<array<string, mixed>> $operations
     * @param array<string, mixed> $expected
     */
    #[DataProvider('shapes')]
    public function testItReadsWhatProvidersSend(array $operations, array $expected): void
    {
        // Arrange
        // (the operations come from the provider's data set)

        // Act
        $read = ScimPatch::read($operations);

        // Assert
        self::assertSame($expected, $read['changes']);
        self::assertSame([], $read['ignored']);
    }

    public function testPathsWeDoNotKnowAreSkippedAndReported(): void
    {
        // Arrange
        $operations = [['op' => 'replace', 'path' => 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User:department', 'value' => 'Sales'], ['op' => 'replace', 'path' => 'active', 'value' => true]];

        // Act
        $read = ScimPatch::read($operations);

        // Assert
        self::assertSame(['active' => true], $read['changes']);
        self::assertSame(['urn:ietf:params:scim:schemas:extension:enterprise:2.0:User:department'], $read['ignored']);
    }

    public function testAPersonCannotLoseTheirUserNameOrState(): void
    {
        // Arrange
        $operations = [['op' => 'remove', 'path' => 'userName'], ['op' => 'remove', 'path' => 'active']];

        // Act
        $read = ScimPatch::read($operations);

        // Assert
        self::assertSame([], $read['changes']);
        self::assertSame(['userName', 'active'], $read['ignored']);
    }

    /** @return iterable<string, array{list<mixed>}> */
    public static function broken(): iterable
    {
        yield 'an unknown operation' => [[['op' => 'move', 'path' => 'active']]];
        yield 'no op at all' => [[['path' => 'active', 'value' => true]]];
        yield 'not an operation' => [['replace']];
        yield 'no path and a plain value' => [[['op' => 'replace', 'value' => 'text']]];
        yield 'active that is not a boolean' => [[['op' => 'replace', 'path' => 'active', 'value' => 'maybe']]];
        yield 'an email that is not text' => [[['op' => 'replace', 'path' => 'emails', 'value' => 5]]];
        yield 'a name that is not text' => [[['op' => 'replace', 'path' => 'name.givenName', 'value' => ['x']]]];
    }

    /** @param list<mixed> $operations */
    #[DataProvider('broken')]
    public function testSomethingMalformedIsAnErrorForTheCaller(array $operations): void
    {
        // Arrange
        // (the operations come from the data set)

        // Act
        $read = fn () => ScimPatch::read($operations);

        // Assert
        $this->expectException(ScimException::class);
        $read();
    }
}
