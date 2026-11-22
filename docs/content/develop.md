---
title: "Develop"
weight: 4
lead: "Run the checks."
---

```
composer install
composer check     # phpstan, then phpunit
```

The tests run the services and the controller against in-memory implementations of every interface (`tests/support`), so they
need no database and no application. A test checks that `src/` never depends on an application. Real adapters are exercised
in the application that uses this library (`php-sso`).
