---
title: "Wiring"
weight: 3
lead: "Put it together in your application."
---

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
