<?php

declare(strict_types=1);

namespace PhpSso\ScimServer\Tests\Unit\ScimService;

use PhpSso\ScimServer\ScimException;
use PhpSso\ScimServer\ScimService;
use PhpSso\ScimServer\Tests\Support\Support;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversMethod(ScimService::class, 'create')]
#[CoversMethod(ScimService::class, 'representation')]
final class CreateTest extends TestCase
{
    public function testItCreatesAnActivePersonWithoutAdminRights(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $user = $app->service->create($app->client, Support::payload('Alice@Acme.com'));

        // Assert
        self::assertSame(['alice@acme.com', 'Alice Acme'], [$user->email, $user->name]);
        self::assertSame(['Alice@Acme.com', 'ext-Alice@Acme.com', 'Alice', 'Acme'], [$user->userName, $user->externalId, $user->givenName, $user->familyName]);
        self::assertSame([true, false], [$user->active, $user->isAdmin]);
        self::assertSame($app->client->id, $app->users->managedBy($user->sub));
    }

    public function testTheRepresentationIsWhatProvidersExpect(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->service->create($app->client, Support::payload('alice@acme.com'));

        // Act
        $scim = $app->service->representation($user);

        // Assert
        self::assertSame($user->sub, $scim['id']);
        self::assertSame('alice@acme.com', $scim['userName']);
        self::assertSame(['formatted' => 'Alice Acme', 'givenName' => 'Alice', 'familyName' => 'Acme'], $scim['name']);
        self::assertSame([['value' => 'alice@acme.com', 'type' => 'work', 'primary' => true]], $scim['emails']);
        self::assertTrue($scim['active']);
        self::assertSame(['resourceType' => 'User', 'location' => 'https://sso.test/scim/v2/Users/' . $user->sub], $scim['meta']);
    }

    public function testActiveFalseCreatesALockedAccount(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $user = $app->service->create($app->client, Support::payload('alice@acme.com', ['active' => false]));

        // Assert
        self::assertFalse($user->active);
        self::assertFalse($app->accounts->isActive($user->sub));
        self::assertFalse($app->service->representation($user)['active']);
    }

    public function testAnAddressOutsideTheTokensDomainsIsRefused(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $create = fn () => $app->service->create($app->client, Support::payload('ceo@other-company.com'));

        // Assert
        $this->expectException(ScimException::class);
        $this->expectExceptionMessage('outside the domains');
        $create();
    }

    public function testASubdomainIsNotTheDomain(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $create = fn () => $app->service->create($app->client, Support::payload('x@evil.acme.com'));

        // Assert
        $this->expectException(ScimException::class);
        $create();
    }

    public function testTheSameUserNameTwiceIsAConflict(): void
    {
        // Arrange
        $app = new Support();
        $app->service->create($app->client, Support::payload('alice@acme.com'));

        // Act
        $again = fn () => $app->service->create($app->client, Support::payload('alice@acme.com', ['emails' => [['value' => 'other@acme.com']]]));

        // Assert
        try {
            $again();
            self::fail('A duplicate was accepted.');
        } catch (ScimException $e) {
            self::assertSame([409, 'uniqueness'], [$e->status, $e->scimType]);
        }
    }

    public function testTheSameExternalIdTwiceIsAConflict(): void
    {
        // Arrange
        $app = new Support();
        $app->service->create($app->client, Support::payload('alice@acme.com', ['externalId' => 'same']));

        // Act
        $again = fn () => $app->service->create($app->client, Support::payload('bob@acme.com', ['externalId' => 'same']));

        // Assert
        try {
            $again();
            self::fail('A duplicate externalId was accepted.');
        } catch (ScimException $e) {
            self::assertSame([409, 'uniqueness'], [$e->status, $e->scimType]);
        }
    }

    public function testARaceThatTakesTheUserNameAfterTheCheckIsStillAConflict(): void
    {
        // Arrange
        $app = new Support();
        $app->users->raceOnWrite = true;

        // Act
        $create = fn () => $app->service->create($app->client, Support::payload('alice@acme.com'));

        // Assert
        try {
            $create();
            self::fail('A lost race was accepted.');
        } catch (ScimException $e) {
            self::assertSame([409, 'uniqueness'], [$e->status, $e->scimType]);
        }
    }

    public function testAnEmailAddressAnotherAccountUsesIsAConflictByDefault(): void
    {
        // Arrange
        $app = new Support();
        $app->accounts->add('Alice', 'alice@acme.com');

        // Act
        $create = fn () => $app->service->create($app->client, Support::payload('alice@acme.com'));

        // Assert
        try {
            $create();
            self::fail('An account with that address was taken over.');
        } catch (ScimException $e) {
            self::assertSame(409, $e->status);
        }
    }

    public function testWhenAllowedItTakesOverAnExistingAccountWithTheSameEmail(): void
    {
        // Arrange
        $app = new Support(adopt: true);
        $sub = $app->accounts->add('Alice Old', 'alice@acme.com');

        // Act
        $user = $app->service->create($app->client, Support::payload('alice@acme.com'));

        // Assert
        self::assertSame($sub, $user->sub);
        self::assertSame('Alice Acme', $user->name);
        self::assertSame($app->client->id, $app->users->managedBy($sub));
    }

    public function testAnAdministratorIsNeverTakenOver(): void
    {
        // Arrange
        $app = new Support(adopt: true);
        $app->accounts->add('Root', 'root@acme.com', true);

        // Act
        $create = fn () => $app->service->create($app->client, Support::payload('root@acme.com'));

        // Assert
        $this->expectException(ScimException::class);
        $create();
    }

    public function testAnAccountAnotherClientManagesIsNotTakenOver(): void
    {
        // Arrange
        $app = new Support(adopt: true);
        $other = $app->clients->add('Beta', ['acme.com'], adoptExisting: true);
        $sub = $app->accounts->add('Alice', 'alice@acme.com');
        $app->users->attach($other->id, $sub, 'alice@acme.com', null, null, null);

        // Act
        $create = fn () => $app->service->create($app->client, Support::payload('alice@acme.com'));

        // Assert
        $this->expectException(ScimException::class);
        $create();
    }

    public function testANewPersonOutsideTheAllowedEmailDomainsIsRefused(): void
    {
        // Arrange
        $app = new Support(allowedDomains: ['other.com']);

        // Act
        $create = fn () => $app->service->create($app->client, Support::payload('alice@acme.com'));

        // Assert
        try {
            $create();
            self::fail('A person outside the allowed domains was created.');
        } catch (ScimException $e) {
            self::assertSame([400, 'invalidValue'], [$e->status, $e->scimType]);
        }
        self::assertNull($app->accounts->findByEmail('alice@acme.com'));
    }

    public function testTheAllowedEmailDomainsDoNotStopAClientTakingOverAnExistingAccount(): void
    {
        // Arrange
        $app = new Support(adopt: true, allowedDomains: ['other.com']);
        $sub = $app->accounts->add('Alice', 'alice@acme.com');

        // Act
        $user = $app->service->create($app->client, Support::payload('alice@acme.com'));

        // Assert
        self::assertSame($sub, $user->sub);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalid(): iterable
    {
        yield 'no userName' => [['userName' => '']];
        yield 'no email anywhere' => [['userName' => 'alice', 'emails' => []]];
        yield 'an email that is not one' => [['userName' => 'alice', 'emails' => [['value' => 'not-an-email']]]];
        yield 'active that is not a boolean' => [['active' => 'maybe']];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('invalid')]
    public function testSomethingMalformedIsRefused(array $override): void
    {
        // Arrange
        $app = new Support();

        // Act
        $create = fn () => $app->service->create($app->client, $override + Support::payload('alice@acme.com'));

        // Assert
        try {
            $create();
            self::fail('A malformed request was accepted.');
        } catch (ScimException $e) {
            self::assertSame([400, 'invalidValue'], [$e->status, $e->scimType]);
        }
    }
}
