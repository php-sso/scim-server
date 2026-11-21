<?php

declare(strict_types=1);

namespace PhpSso\ScimServer\Tests\Unit\ScimGroupPatch;

use PhpSso\ScimServer\ScimException;
use PhpSso\ScimServer\ScimGroupPatch;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversMethod(ScimGroupPatch::class, 'read')]
#[CoversMethod(ScimGroupPatch::class, 'subs')]
final class ReadTest extends TestCase
{
    /** @return iterable<string, array{list<array<string, mixed>>, array<string, ?string>, list<array{op: string, subs: ?list<string>}>}> */
    public static function shapes(): iterable
    {
        yield 'add members as a list' => [[['op' => 'add', 'path' => 'members', 'value' => [['value' => 'u1'], ['value' => 'u2']]]], [], [['op' => 'add', 'subs' => ['u1', 'u2']]]];
        yield 'add one member as an object' => [[['op' => 'Add', 'path' => 'members', 'value' => ['value' => 'u1']]], [], [['op' => 'add', 'subs' => ['u1']]]];
        yield 'remove one member by a filter, the way Entra does' => [[['op' => 'Remove', 'path' => 'members[value eq "u1"]']], [], [['op' => 'remove', 'subs' => ['u1']]]];
        yield 'remove members by value' => [[['op' => 'remove', 'path' => 'members', 'value' => [['value' => 'u1']]]], [], [['op' => 'remove', 'subs' => ['u1']]]];
        yield 'remove everyone' => [[['op' => 'remove', 'path' => 'members']], [], [['op' => 'remove', 'subs' => null]]];
        yield 'replace the members' => [[['op' => 'replace', 'path' => 'members', 'value' => [['value' => 'u3']]]], [], [['op' => 'replace', 'subs' => ['u3']]]];
        yield 'rename' => [[['op' => 'replace', 'path' => 'displayName', 'value' => 'Staff']], ['displayName' => 'Staff'], []];
        yield 'no path, an object' => [[['op' => 'replace', 'value' => ['displayName' => 'Staff', 'members' => [['value' => 'u1']]]]], ['displayName' => 'Staff'], [['op' => 'replace', 'subs' => ['u1']]]];
        yield 'steps keep their order' => [[['op' => 'add', 'path' => 'members', 'value' => [['value' => 'u1']]], ['op' => 'remove', 'path' => 'members[value eq "u1"]']], [], [['op' => 'add', 'subs' => ['u1']], ['op' => 'remove', 'subs' => ['u1']]]];
        yield 'removing the externalId' => [[['op' => 'remove', 'path' => 'externalId']], ['externalId' => null], []];
    }

    /**
     * @param list<array<string, mixed>> $operations
     * @param array<string, ?string> $changes
     * @param list<array{op: string, subs: ?list<string>}> $members
     */
    #[DataProvider('shapes')]
    public function testItReadsWhatProvidersSend(array $operations, array $changes, array $members): void
    {
        // Arrange
        // (the operations come from the provider's data set)

        // Act
        $read = ScimGroupPatch::read($operations);

        // Assert
        self::assertSame($changes, $read['changes']);
        self::assertSame($members, $read['members']);
        self::assertSame([], $read['ignored']);
    }

    public function testPathsWeDoNotKnowAreSkippedAndAGroupCannotLoseItsName(): void
    {
        // Arrange
        $operations = [['op' => 'replace', 'path' => 'urn:custom:owner', 'value' => 'x'], ['op' => 'remove', 'path' => 'displayName']];

        // Act
        $read = ScimGroupPatch::read($operations);

        // Assert
        self::assertSame([], $read['changes']);
        self::assertSame(['urn:custom:owner', 'displayName'], $read['ignored']);
    }

    /** @return iterable<string, array{list<mixed>}> */
    public static function broken(): iterable
    {
        yield 'an unknown operation' => [[['op' => 'move', 'path' => 'members']]];
        yield 'no op' => [[['path' => 'members']]];
        yield 'a member without a value' => [[['op' => 'add', 'path' => 'members', 'value' => [['display' => 'x']]]]];
        yield 'members that is not a list' => [[['op' => 'add', 'path' => 'members', 'value' => 5]]];
        yield 'a name that is not text' => [[['op' => 'replace', 'path' => 'displayName', 'value' => ['x']]]];
        yield 'no path and a plain value' => [[['op' => 'replace', 'value' => 'text']]];
    }

    /** @param list<mixed> $operations */
    #[DataProvider('broken')]
    public function testSomethingMalformedIsAnErrorForTheCaller(array $operations): void
    {
        // Arrange
        // (the operations come from the data set)

        // Act
        $read = fn () => ScimGroupPatch::read($operations);

        // Assert
        $this->expectException(ScimException::class);
        $read();
    }
}
