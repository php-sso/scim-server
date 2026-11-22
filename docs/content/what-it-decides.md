---
title: "What it decides, and what it leaves to you"
weight: 2
lead: "The protocol rules the library owns, and the parts that are yours."
---

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
