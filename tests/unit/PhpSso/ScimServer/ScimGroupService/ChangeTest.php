<?php

declare(strict_types=1);

namespace PhpSso\ScimServer\Tests\Unit\ScimGroupService;

use PhpSso\ScimServer\ScimException;
use PhpSso\ScimServer\ScimGroup;
use PhpSso\ScimServer\ScimGroupService;
use PhpSso\ScimServer\Tests\Support\Support;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(ScimGroupService::class, 'replace')]
#[CoversMethod(ScimGroupService::class, 'patch')]
#[CoversMethod(ScimGroupService::class, 'delete')]
#[CoversMethod(ScimGroupService::class, 'get')]
final class ChangeTest extends TestCase
{
    private function person(Support $app, string $userName): string
    {
        return $app->service->create($app->client, Support::payload($userName))->sub;
    }

    /** @param list<string> $members */
    private function group(Support $app, string $name = 'Engineering', array $members = []): ScimGroup
    {
        return $app->groupService->create($app->client, ['displayName' => $name, 'members' => array_map(static fn (string $s): array => ['value' => $s], $members)]);
    }

    public function testAddingMembersLeavesTheOthersAlone(): void
    {
        // Arrange
        $app = new Support();
        [$alice, $bob] = [$this->person($app, 'alice@acme.com'), $this->person($app, 'bob@acme.com')];
        $group = $this->group($app, 'Engineering', [$alice]);

        // Act
        $app->groupService->patch($app->client, $group->id, [['op' => 'add', 'path' => 'members', 'value' => [['value' => $bob]]]]);

        // Assert
        self::assertEqualsCanonicalizing([$alice, $bob], $app->groups->members($group->id));
    }

    public function testRemovingOneMemberByFilterLeavesTheOthers(): void
    {
        // Arrange
        $app = new Support();
        [$alice, $bob] = [$this->person($app, 'alice@acme.com'), $this->person($app, 'bob@acme.com')];
        $group = $this->group($app, 'Engineering', [$alice, $bob]);

        // Act
        $app->groupService->patch($app->client, $group->id, [['op' => 'remove', 'path' => 'members[value eq "' . $alice . '"]']]);

        // Assert
        self::assertSame([$bob], $app->groups->members($group->id));
    }

    public function testRemovingEveryoneEmptiesTheGroup(): void
    {
        // Arrange
        $app = new Support();
        $group = $this->group($app, 'Engineering', [$this->person($app, 'alice@acme.com')]);

        // Act
        $app->groupService->patch($app->client, $group->id, [['op' => 'remove', 'path' => 'members']]);

        // Assert
        self::assertSame([], $app->groups->members($group->id));
    }

    public function testRemovingSomeoneWhoIsNotInTheGroupIsHarmless(): void
    {
        // Arrange
        $app = new Support();
        $alice = $this->person($app, 'alice@acme.com');
        $group = $this->group($app, 'Engineering', [$alice]);

        // Act
        $app->groupService->patch($app->client, $group->id, [['op' => 'remove', 'path' => 'members[value eq "nobody"]']]);

        // Assert
        self::assertSame([$alice], $app->groups->members($group->id));
    }

    public function testReplacingTheMembersMakesExactlyThoseTheMembers(): void
    {
        // Arrange
        $app = new Support();
        [$alice, $bob] = [$this->person($app, 'alice@acme.com'), $this->person($app, 'bob@acme.com')];
        $group = $this->group($app, 'Engineering', [$alice]);

        // Act
        $app->groupService->patch($app->client, $group->id, [['op' => 'replace', 'path' => 'members', 'value' => [['value' => $bob]]]]);

        // Assert
        self::assertSame([$bob], $app->groups->members($group->id));
    }

    public function testASingleBadMemberChangesNothingAtAll(): void
    {
        // Arrange
        $app = new Support();
        $alice = $this->person($app, 'alice@acme.com');
        $stranger = $app->stranger();
        $group = $this->group($app, 'Engineering');

        // Act
        $patch = fn () => $app->groupService->patch($app->client, $group->id, [
            ['op' => 'replace', 'path' => 'displayName', 'value' => 'Renamed'],
            ['op' => 'add', 'path' => 'members', 'value' => [['value' => $alice], ['value' => $stranger]]],
        ]);

        // Assert
        try {
            $patch();
            self::fail('A stranger was added.');
        } catch (ScimException $e) {
            self::assertSame(400, $e->status);
        }
        self::assertSame([], $app->groups->members($group->id));
        self::assertSame('Engineering', $app->directory->nameOf($group->id));
    }

    public function testPatchReportsWhatItIgnored(): void
    {
        // Arrange
        $app = new Support();
        $group = $this->group($app);

        // Act
        $result = $app->groupService->patch($app->client, $group->id, [['op' => 'replace', 'path' => 'urn:custom:owner', 'value' => 'x']]);

        // Assert
        self::assertSame(['urn:custom:owner'], $result['ignored']);
    }

    public function testRenamingChangesTheNameAppsSee(): void
    {
        // Arrange
        $app = new Support(groupPrefix: 'acme:');
        $group = $this->group($app, 'Engineering');

        // Act
        $result = $app->groupService->patch($app->client, $group->id, [['op' => 'replace', 'path' => 'displayName', 'value' => 'Platform Team']]);

        // Assert
        self::assertSame(['acme:Platform-Team', 'Platform Team'], [$result['group']->name, $result['group']->displayName]);
        self::assertSame('acme:Platform-Team', $app->directory->nameOf($group->id));
    }

    public function testRenamingToAnExistingGroupIsAConflict(): void
    {
        // Arrange
        $app = new Support();
        $app->directory->add('Taken', 'Mine');
        $group = $this->group($app, 'Engineering');

        // Act
        $rename = fn () => $app->groupService->patch($app->client, $group->id, [['op' => 'replace', 'path' => 'displayName', 'value' => 'taken']]);

        // Assert
        try {
            $rename();
            self::fail('A group of yours was overwritten by a rename.');
        } catch (ScimException $e) {
            self::assertSame(409, $e->status);
        }
    }

    public function testRenamingToAnotherManagedGroupsDisplayNameIsAConflict(): void
    {
        // Arrange
        $app = new Support();
        $this->group($app, 'Sales');
        $group = $this->group($app, 'Engineering');

        // Act
        $rename = fn () => $app->groupService->patch($app->client, $group->id, [['op' => 'replace', 'path' => 'displayName', 'value' => 'sales']]);

        // Assert
        $this->expectException(ScimException::class);
        $this->expectExceptionMessage('displayName already exists');
        $rename();
    }

    public function testReplaceSetsTheWholeGroup(): void
    {
        // Arrange
        $app = new Support();
        [$alice, $bob] = [$this->person($app, 'alice@acme.com'), $this->person($app, 'bob@acme.com')];
        $group = $this->group($app, 'Engineering', [$alice]);

        // Act
        $replaced = $app->groupService->replace($app->client, $group->id, ['displayName' => 'Engineering', 'externalId' => 'new-ext', 'members' => [['value' => $bob]]]);

        // Assert
        self::assertSame('new-ext', $replaced->externalId);
        self::assertSame([$bob], $app->groups->members($group->id));
    }

    public function testAClientCannotTouchAGroupItDidNotCreate(): void
    {
        // Arrange
        $app = new Support();
        $mine = $app->directory->add('Staff', 'Mine');

        // Act
        $touch = fn () => $app->groupService->patch($app->client, $mine, [['op' => 'add', 'path' => 'members', 'value' => []]]);

        // Assert
        try {
            $touch();
            self::fail('A group of yours was changed.');
        } catch (ScimException $e) {
            self::assertSame(404, $e->status);
        }
    }

    public function testAClientCannotTouchAnotherClientsGroup(): void
    {
        // Arrange
        $app = new Support();
        $group = $this->group($app);
        $other = $app->clients->add('Beta', ['beta.com']);

        // Act
        $get = fn () => $app->groupService->get($other, $group->id);

        // Assert
        $this->expectException(ScimException::class);
        $get();
    }

    public function testDeletingRemovesTheGroupAndItsMembers(): void
    {
        // Arrange
        $app = new Support();
        $alice = $this->person($app, 'alice@acme.com');
        $group = $this->group($app, 'Engineering', [$alice]);

        // Act
        $app->groupService->delete($app->client, $group->id);

        // Assert
        self::assertFalse($app->directory->exists($group->id));
        self::assertNull($app->groups->managedBy($group->id));
        self::assertSame([], $app->groups->members($group->id));
        self::assertTrue($app->accounts->exists($alice));
    }

    public function testDeactivatingAPersonKeepsTheirGroups(): void
    {
        // Arrange
        $app = new Support();
        $alice = $this->person($app, 'alice@acme.com');
        $group = $this->group($app, 'Engineering', [$alice]);

        // Act
        $app->service->patch($app->client, $alice, [['op' => 'replace', 'path' => 'active', 'value' => false]]);

        // Assert
        self::assertSame([$alice], $app->groups->members($group->id));
    }
}
