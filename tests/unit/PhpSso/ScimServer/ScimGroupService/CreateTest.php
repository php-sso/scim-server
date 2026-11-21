<?php

declare(strict_types=1);

namespace PhpSso\ScimServer\Tests\Unit\ScimGroupService;

use PhpSso\ScimServer\ScimException;
use PhpSso\ScimServer\ScimGroupService;
use PhpSso\ScimServer\Tests\Support\Support;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversMethod(ScimGroupService::class, 'create')]
#[CoversMethod(ScimGroupService::class, 'representation')]
final class CreateTest extends TestCase
{
    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function payload(string $name = 'Engineering', array $extra = []): array
    {
        return $extra + ['schemas' => ['urn:ietf:params:scim:schemas:core:2.0:Group'], 'displayName' => $name, 'externalId' => 'grp-' . $name];
    }

    private function person(Support $app, string $userName): string
    {
        return $app->service->create($app->client, Support::payload($userName))->sub;
    }

    public function testItCreatesAGroupAppsCanSeeUnderItsName(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $group = $app->groupService->create($app->client, self::payload('Engineering'));

        // Assert
        self::assertSame(['Engineering', 'Engineering', 'grp-Engineering'], [$group->name, $group->displayName, $group->externalId]);
        self::assertSame('Provisioned by Acme Okta', $group->description);
        self::assertNotNull($app->directory->findByName('Engineering'));
    }

    public function testSpacesAndOtherCharactersBecomeDashesButTheProvidersNameIsKept(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $group = $app->groupService->create($app->client, self::payload('All Employees / EU'));

        // Assert
        self::assertSame(['All-Employees-EU', 'All Employees / EU'], [$group->name, $group->displayName]);
        self::assertSame('All Employees / EU', $app->groupService->representation($group)['displayName']);
    }

    public function testTheClientsPrefixKeepsItsGroupsApartFromYours(): void
    {
        // Arrange
        $app = new Support(groupPrefix: 'acme:');

        // Act
        $group = $app->groupService->create($app->client, self::payload('Engineering'));

        // Assert
        self::assertSame('acme:Engineering', $group->name);
        self::assertSame('Engineering', $app->groupService->representation($group)['displayName']);
    }

    public function testItAddsOnlyPeopleTheClientManages(): void
    {
        // Arrange
        $app = new Support();
        $alice = $this->person($app, 'alice@acme.com');
        $bob = $this->person($app, 'bob@acme.com');

        // Act
        $group = $app->groupService->create($app->client, self::payload('Engineering', ['members' => [['value' => $alice], ['value' => $bob]]]));

        // Assert
        $listed = $app->groupService->representation($group)['members'];
        self::assertIsArray($listed);
        self::assertEqualsCanonicalizing([$alice, $bob], array_column($listed, 'value'));
    }

    public function testTheRepresentationNamesEachMemberAndLinksToThem(): void
    {
        // Arrange
        $app = new Support();
        $alice = $this->person($app, 'alice@acme.com');
        $group = $app->groupService->create($app->client, self::payload('Engineering', ['members' => [['value' => $alice]]]));

        // Act
        $scim = $app->groupService->representation($group);

        // Assert
        self::assertSame([['value' => $alice, 'display' => 'Alice Acme', 'type' => 'User', '$ref' => 'https://sso.test/scim/v2/Users/' . $alice]], $scim['members']);
        self::assertSame(['resourceType' => 'Group', 'location' => 'https://sso.test/scim/v2/Groups/' . $group->id], $scim['meta']);
    }

    public function testSomeoneItDoesNotManageCannotBeAdded(): void
    {
        // Arrange
        $app = new Support();
        $stranger = $app->stranger();

        // Act
        $create = fn () => $app->groupService->create($app->client, self::payload('Engineering', ['members' => [['value' => $stranger]]]));

        // Assert
        try {
            $create();
            self::fail('A stranger was added to a group.');
        } catch (ScimException $e) {
            self::assertSame([400, 'invalidValue'], [$e->status, $e->scimType]);
            self::assertStringContainsString('not a person this client manages', $e->getMessage());
        }
        self::assertNull($app->directory->findByName('Engineering'));
    }

    public function testAnAdministratorCannotBePulledInEitherUnlessTheClientManagesThem(): void
    {
        // Arrange
        $app = new Support();
        $root = $app->accounts->add('Root', 'root@acme.com', true);

        // Act
        $create = fn () => $app->groupService->create($app->client, self::payload('Engineering', ['members' => [['value' => $root]]]));

        // Assert
        $this->expectException(ScimException::class);
        $create();
    }

    public function testAGroupWithTheNameOfOneYouMadeIsRefused(): void
    {
        // Arrange
        $app = new Support();
        $mine = $app->directory->add('admins', 'Mine');

        // Act
        $create = fn () => $app->groupService->create($app->client, self::payload('Admins'));

        // Assert
        try {
            $create();
            self::fail('A group of yours was taken over.');
        } catch (ScimException $e) {
            self::assertSame([409, 'uniqueness'], [$e->status, $e->scimType]);
        }
        self::assertSame([], $app->groups->members($mine));
        self::assertNull($app->groups->managedBy($mine));
    }

    public function testWhenAllowedItTakesOverAGroupThatExists(): void
    {
        // Arrange
        $app = new Support(adoptGroups: true);
        $mine = $app->directory->add('Engineering', 'Mine');
        $alice = $this->person($app, 'alice@acme.com');

        // Act
        $group = $app->groupService->create($app->client, self::payload('Engineering', ['members' => [['value' => $alice]]]));

        // Assert
        self::assertSame($mine, $group->id);
        self::assertSame($app->client->id, $app->groups->managedBy($mine));
        self::assertSame([$alice], $app->groups->members($mine));
    }

    public function testAGroupAnotherClientManagesIsNeverTakenOver(): void
    {
        // Arrange
        $app = new Support(adoptGroups: true);
        $app->groupService->create($app->client, self::payload('Engineering'));
        $other = $app->clients->add('Beta', ['beta.com'], adoptGroups: true);

        // Act
        $create = fn () => $app->groupService->create($other, self::payload('Engineering'));

        // Assert
        $this->expectException(ScimException::class);
        $create();
    }

    public function testTheSameDisplayNameTwiceIsAConflict(): void
    {
        // Arrange
        $app = new Support();
        $app->groupService->create($app->client, self::payload('Engineering'));

        // Act
        $again = fn () => $app->groupService->create($app->client, self::payload('engineering', ['externalId' => 'other']));

        // Assert
        $this->expectException(ScimException::class);
        $this->expectExceptionMessage('already exists');
        $again();
    }

    public function testTheSameExternalIdTwiceIsAConflict(): void
    {
        // Arrange
        $app = new Support();
        $app->groupService->create($app->client, self::payload('Engineering', ['externalId' => 'same']));

        // Act
        $again = fn () => $app->groupService->create($app->client, self::payload('Sales', ['externalId' => 'same']));

        // Assert
        $this->expectException(ScimException::class);
        $this->expectExceptionMessage('externalId already exists');
        $again();
    }

    public function testARaceThatTakesTheNameAfterTheCheckIsStillAConflict(): void
    {
        // Arrange
        $app = new Support();
        $app->groups->raceOnWrite = true;

        // Act
        $create = fn () => $app->groupService->create($app->client, self::payload('Engineering'));

        // Assert
        try {
            $create();
            self::fail('A lost race was accepted.');
        } catch (ScimException $e) {
            self::assertSame([409, 'uniqueness'], [$e->status, $e->scimType]);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function badNames(): iterable
    {
        yield 'only symbols' => ['***'];
        yield 'too long once cleaned' => [str_repeat('x', 65)];
        yield 'nothing' => [''];
    }

    #[DataProvider('badNames')]
    public function testANameThatCannotBeAGroupNameIsRefused(string $name): void
    {
        // Arrange
        $app = new Support();

        // Act
        $create = fn () => $app->groupService->create($app->client, self::payload($name));

        // Assert
        try {
            $create();
            self::fail('A bad name was accepted.');
        } catch (ScimException $e) {
            self::assertSame([400, 'invalidValue'], [$e->status, $e->scimType]);
        }
    }

    public function testThePrefixCountsTowardsTheLengthLimit(): void
    {
        // Arrange
        $app = new Support(groupPrefix: 'acme:');

        // Act
        $create = fn () => $app->groupService->create($app->client, self::payload(str_repeat('x', 60)));

        // Assert
        $this->expectException(ScimException::class);
        $create();
    }
}
