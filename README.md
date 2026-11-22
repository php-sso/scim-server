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

## Documentation

The documentation is at **<https://php-sso.github.io/scim-server/>**:

- [What it decides, and what it leaves to you](https://php-sso.github.io/scim-server/what-it-decides/)
- [Wiring](https://php-sso.github.io/scim-server/wiring/)

The pages are in [`docs/content`](docs/content), built with Hugo and the
[php-sso docs theme](https://github.com/php-sso/docs-theme). Preview them with `hugo server --source docs`.

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
