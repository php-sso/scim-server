# php-sso/scim-server

A SCIM 2.0 server (RFC 7643 and RFC 7644) for PSR-7 applications: a company's provisioning system (Okta, Entra, and others)
creates, updates and deactivates people and groups in your application.

- PSR-7 messages in and out; responses are built with PSR-17 factories. It fits any framework.
- No third-party runtime dependencies: only the PSR interface packages, `ext-json` and `ext-mbstring`.
- Your accounts, groups and storage are interfaces you implement. Nothing here knows about your database, how you name
  sign-in users, or what locking an account means.
- Users and Groups, with `GET`, `POST`, `PUT`, `PATCH` and `DELETE`, `eq` filters and paging. No bulk, sorting or ETags
  (`/ServiceProviderConfig` says so).

## Install

```
composer require php-sso/scim-server
```

PHP 8.2 or newer, with `ext-json` and `ext-mbstring`.

## What it decides, and what it leaves to you

The library owns the protocol and the rules that define how provisioning behaves:

- A client manages **only the people and groups it created** (or took over, when you allow it). It never touches an
  administrator, and it only puts people it manages in its groups.
- A client's **email domains** limit which addresses it may manage.
- What a `DELETE` does is the client's setting: deactivate (the default) or remove the account.
- Group names are cleaned to your group-name rule, optionally behind the client's prefix, and a group with an existing name
  is refused unless the client may adopt groups.

Everything about your application is behind the interfaces in `PhpSso\ScimServer\Contract`:

| Interface | You provide |
| --- | --- |
| `ClientAuthenticator` | Which provisioning client a bearer token belongs to (a `ProvisioningClient`), or none. |
| `ManagedUsers` | Which people each client manages, with the client's own `userName` and `externalId` for them. |
| `ManagedGroups` | The same for groups, plus who is in them. |
| `Accounts` | Your accounts: find by email, create, update, activate or deactivate, delete, and "is this address free". |
| `GroupDirectory` | Your own groups (the ones your apps see), by name. |
| `EventLog` | Where requests are noted, for an administrator to read. |

Policy that is yours, such as which email domains your application accepts at all, belongs in your `Accounts`
implementation (for example in `emailError()`), not here.

A port reports "that value is already taken" by throwing `UniquenessViolation`; the server turns it into a SCIM `409`.

## Wiring

```php
use PhpSso\ScimServer\ScimController;
use PhpSso\ScimServer\ScimGroupService;
use PhpSso\ScimServer\ScimService;

$controller = new ScimController(
    $clientAuthenticator,                                                   // ClientAuthenticator
    new ScimService($managedUsers, $accounts, $baseUrl),
    new ScimGroupService($groupDirectory, $managedGroups, $managedUsers, $accounts, $baseUrl),
    $managedUsers,
    $managedGroups,
    $eventLog,
    $responseFactory,                                                       // PSR-17
    $streamFactory,                                                         // PSR-17
    $baseUrl,                                                               // e.g. https://sso.example.com
);

$response = $controller->handle($request); // requests under {baseUrl}/scim/v2
```

The controller expects the endpoints under `/scim/v2` and answers `401` with `WWW-Authenticate: Bearer` for a missing or
unknown token. Every request it handles, other than that, is passed to `EventLog`.

## Development

```
composer install
composer check     # phpstan, then phpunit
```

The tests run the services and the controller against in-memory implementations of every interface (`tests/support`), so they
need no database and no application. A test checks that `src/` never depends on an application. Real adapters are exercised
in the application that uses this library (`php-sso`).

## License

MIT. See [LICENSE](LICENSE).
