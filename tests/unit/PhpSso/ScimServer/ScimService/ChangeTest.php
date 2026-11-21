<?php

declare(strict_types=1);

namespace PhpSso\ScimServer\Tests\Unit\ScimService;

use PhpSso\ScimServer\OnDelete;
use PhpSso\ScimServer\ScimException;
use PhpSso\ScimServer\ScimService;
use PhpSso\ScimServer\Tests\Support\Support;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(ScimService::class, 'replace')]
#[CoversMethod(ScimService::class, 'patch')]
#[CoversMethod(ScimService::class, 'delete')]
#[CoversMethod(ScimService::class, 'get')]
final class ChangeTest extends TestCase
{
    public function testDeactivatingLocksTheAccountAndEndsItsSessions(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->service->create($app->client, Support::payload('alice@acme.com'));

        // Act
        $result = $app->service->patch($app->client, $user->sub, [['op' => 'replace', 'path' => 'active', 'value' => false]]);

        // Assert
        self::assertFalse($result['user']->active);
        self::assertFalse($app->accounts->isActive($user->sub));
        self::assertGreaterThan(0, $app->accounts->sessionsEnded($user->sub));
    }

    public function testReactivatingLetsThemBackIn(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->service->create($app->client, Support::payload('alice@acme.com', ['active' => false]));

        // Act
        $result = $app->service->patch($app->client, $user->sub, [['op' => 'replace', 'path' => 'active', 'value' => 'True']]);

        // Assert
        self::assertTrue($result['user']->active);
        self::assertTrue($app->accounts->isActive($user->sub));
    }

    public function testPatchChangesOnlyWhatItNames(): void
    {
        // Arrange
        $app = new Support();
        $created = $app->service->create($app->client, Support::payload('alice@acme.com'));

        // Act
        $result = $app->service->patch($app->client, $created->sub, [['op' => 'replace', 'path' => 'name.familyName', 'value' => 'Brown']]);

        // Assert
        $user = $result['user'];
        self::assertSame(['Alice Brown', 'Alice', 'Brown', 'alice@acme.com', 'ext-alice@acme.com'], [$user->name, $user->givenName, $user->familyName, $user->email, $user->externalId]);
    }

    public function testPatchCanRemoveTheExternalIdAndTheNames(): void
    {
        // Arrange
        $app = new Support();
        $created = $app->service->create($app->client, Support::payload('alice@acme.com'));

        // Act
        $result = $app->service->patch($app->client, $created->sub, [
            ['op' => 'remove', 'path' => 'externalId'],
            ['op' => 'remove', 'path' => 'name.givenName'],
        ]);

        // Assert
        $user = $result['user'];
        self::assertSame([null, null, 'Acme'], [$user->externalId, $user->givenName, $user->familyName]);
    }

    public function testPatchReportsWhatItIgnored(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->service->create($app->client, Support::payload('alice@acme.com'));

        // Act
        $result = $app->service->patch($app->client, $user->sub, [['op' => 'replace', 'path' => 'urn:custom:shoeSize', 'value' => '42']]);

        // Assert
        self::assertSame(['urn:custom:shoeSize'], $result['ignored']);
    }

    public function testPatchCanChangeTheEmailWithinTheDomains(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->service->create($app->client, Support::payload('alice@acme.com'));

        // Act
        $result = $app->service->patch($app->client, $user->sub, [['op' => 'replace', 'path' => 'emails[type eq "work"].value', 'value' => 'Alice.Brown@acme.com']]);

        // Assert
        self::assertSame('alice.brown@acme.com', $result['user']->email);
    }

    public function testPatchCannotMoveAnAddressOutOfTheDomains(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->service->create($app->client, Support::payload('alice@acme.com'));

        // Act
        $move = fn () => $app->service->patch($app->client, $user->sub, [['op' => 'replace', 'path' => 'emails', 'value' => 'alice@other-company.com']]);

        // Assert
        $this->expectException(ScimException::class);
        $move();
    }

    public function testPatchCannotMoveAnAddressToADomainThatIsNotAllowed(): void
    {
        // Arrange
        $app = new Support(domains: ['acme.com', 'acme.org']);
        $user = $app->service->create($app->client, Support::payload('alice@acme.com'));
        $app->accounts->allowedDomains = ['acme.com'];

        // Act
        $move = fn () => $app->service->patch($app->client, $user->sub, [['op' => 'replace', 'path' => 'emails', 'value' => 'alice@acme.org']]);

        // Assert
        try {
            $move();
            self::fail('An address was moved to a domain that is not allowed.');
        } catch (ScimException $e) {
            self::assertSame([400, 'invalidValue'], [$e->status, $e->scimType]);
        }
        self::assertSame('alice@acme.com', $app->accounts->email($user->sub));
    }

    public function testPatchCannotTakeAnAddressAnotherAccountUses(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->service->create($app->client, Support::payload('alice@acme.com'));
        $app->accounts->add('Bob', 'bob@acme.com');

        // Act
        $move = fn () => $app->service->patch($app->client, $user->sub, [['op' => 'replace', 'path' => 'emails', 'value' => 'bob@acme.com']]);

        // Assert
        try {
            $move();
            self::fail('An address another account uses was taken.');
        } catch (ScimException $e) {
            self::assertSame([409, 'uniqueness'], [$e->status, $e->scimType]);
        }
    }

    public function testSomeoneWhoWasThereBeforeTheDomainsWereRestrictedCanStillBeChanged(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->service->create($app->client, Support::payload('alice@acme.com'));
        $app->accounts->allowedDomains = ['other.com'];

        // Act
        $result = $app->service->patch($app->client, $user->sub, [['op' => 'replace', 'path' => 'name.familyName', 'value' => 'Brown']]);

        // Assert
        self::assertSame('Alice Brown', $result['user']->name);
    }

    public function testReplaceSetsTheWholeProfile(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->service->create($app->client, Support::payload('alice@acme.com'));

        // Act
        $replaced = $app->service->replace($app->client, $user->sub, Support::payload('alice@acme.com', ['name' => ['formatted' => 'Alice the Great'], 'externalId' => 'new-ext', 'active' => false]));

        // Assert
        self::assertSame(['Alice the Great', 'new-ext', null, false], [$replaced->name, $replaced->externalId, $replaced->givenName, $replaced->active]);
    }

    public function testARaceThatTakesTheUserNameAfterTheCheckIsStillAConflict(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->service->create($app->client, Support::payload('alice@acme.com'));
        $app->users->raceOnWrite = true;

        // Act
        $replace = fn () => $app->service->replace($app->client, $user->sub, Support::payload('alice@acme.com', ['externalId' => 'new-ext']));

        // Assert
        try {
            $replace();
            self::fail('A lost race was accepted.');
        } catch (ScimException $e) {
            self::assertSame([409, 'uniqueness'], [$e->status, $e->scimType]);
        }
    }

    public function testAClientCannotTouchSomeoneItDidNotCreate(): void
    {
        // Arrange
        $app = new Support();
        $stranger = $app->stranger();

        // Act
        $touch = fn () => $app->service->patch($app->client, $stranger, [['op' => 'replace', 'path' => 'active', 'value' => false]]);

        // Assert
        try {
            $touch();
            self::fail('A stranger was changed.');
        } catch (ScimException $e) {
            self::assertSame(404, $e->status);
        }
        self::assertTrue($app->accounts->isActive($stranger));
    }

    public function testAClientCannotTouchAnotherClientsPeople(): void
    {
        // Arrange
        $app = new Support();
        $other = $app->clients->add('Beta', ['acme.com']);
        $user = $app->service->create($app->client, Support::payload('alice@acme.com'));

        // Act
        $get = fn () => $app->service->get($other, $user->sub);

        // Assert
        $this->expectException(ScimException::class);
        $get();
    }

    public function testAnAdministratorCannotBeChangedEvenWhenItWasCreatedHere(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->service->create($app->client, Support::payload('alice@acme.com'));
        $app->accounts->makeAdmin($user->sub);

        // Act
        $deactivate = fn () => $app->service->patch($app->client, $user->sub, [['op' => 'replace', 'path' => 'active', 'value' => false]]);

        // Assert
        try {
            $deactivate();
            self::fail('An administrator was deactivated.');
        } catch (ScimException $e) {
            self::assertSame(403, $e->status);
        }
        self::assertTrue($app->accounts->isActive($user->sub));
    }

    public function testAUserNameAnotherPersonHasIsAConflict(): void
    {
        // Arrange
        $app = new Support();
        $app->service->create($app->client, Support::payload('alice@acme.com'));
        $bob = $app->service->create($app->client, Support::payload('bob@acme.com'));

        // Act
        $rename = fn () => $app->service->patch($app->client, $bob->sub, [['op' => 'replace', 'path' => 'userName', 'value' => 'ALICE@acme.com']]);

        // Assert
        try {
            $rename();
            self::fail('A duplicate userName was accepted.');
        } catch (ScimException $e) {
            self::assertSame(409, $e->status);
        }
    }

    public function testDeleteDeactivatesByDefaultAndKeepsTheRecord(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->service->create($app->client, Support::payload('alice@acme.com'));

        // Act
        $app->service->delete($app->client, $user->sub);

        // Assert
        self::assertTrue($app->accounts->exists($user->sub));
        self::assertFalse($app->accounts->isActive($user->sub));
        self::assertSame($app->client->id, $app->users->managedBy($user->sub));
    }

    public function testDeleteRemovesTheAccountWhenTheClientIsSetToDoThat(): void
    {
        // Arrange
        $app = new Support(OnDelete::Delete);
        $user = $app->service->create($app->client, Support::payload('alice@acme.com'));

        // Act
        $app->service->delete($app->client, $user->sub);

        // Assert
        self::assertFalse($app->accounts->exists($user->sub));
        self::assertNull($app->users->managedBy($user->sub));
        self::assertGreaterThan(0, $app->accounts->sessionsEnded($user->sub));
    }
}
