# Security policy

## Reporting a vulnerability

Please do not open a public issue for a security problem. Report it privately through
GitHub's "Report a vulnerability" button on the Security tab of this repository
(private vulnerability reporting). Include what you found, how to reproduce it, and which version.

What to expect:

- An acknowledgement within 3 working days.
- A first assessment within 10 working days.
- A fix and a coordinated disclosure date agreed with you. The default is 90 days at most.
- Credit in the release notes, unless you prefer to stay anonymous.

## Supported versions

Only the latest minor release of the latest major version receives security fixes.

## Scope

In scope: the code in `src/` (the SCIM endpoints, PATCH and filter parsing, and the rules about which people and groups a
provisioning client may manage). Out of scope: your storage, accounts, token handling and admin screens, and the in-memory
implementations used by the tests.

## What you are responsible for

The library enforces the protocol and the management rules. These parts are yours:

- `ClientAuthenticator`: tokens must be long and random, stored only as a hash, and compared in constant time. Serve the
  endpoints over HTTPS only.
- `Accounts::findByEmail()` must report `isAdmin` truthfully: the library never lets a client manage an administrator, but it
  only knows what you tell it.
- `ManagedUsers` and `ManagedGroups` must report a taken `userName`, `externalId` or display name by throwing
  `UniquenessViolation` (a database constraint is the reliable way), because two requests can race.
- `Accounts::emailPolicyError()` is where your own rules about which addresses may be created belong, such as allowed domains.
- Rate-limit the endpoints and review the request log your `EventLog` keeps.
