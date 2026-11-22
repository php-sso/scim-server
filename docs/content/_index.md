---
title: scim-server
lead: "A SCIM 2.0 server for PSR-7 applications: a company's provisioning system creates, updates and deactivates people and groups."
actions:
  - name: Install it
    url: /installation/
  - name: What it decides, and what it leaves to you
    url: /what-it-decides/
---

A SCIM 2.0 server (RFC 7643 and RFC 7644) for PSR-7 applications: a company's provisioning system (Okta, Entra, and others)
creates, updates and deactivates people and groups in your application.

- PSR-7 messages in and out; responses are built with PSR-17 factories. It fits any framework.
- No third-party runtime dependencies: only the PSR interface packages, `ext-json` and `ext-mbstring`.
- Your accounts, groups and storage are interfaces you implement. Nothing here knows about your database, how you name
  sign-in users, or what locking an account means.
- Users and Groups, with `GET`, `POST`, `PUT`, `PATCH` and `DELETE`, `eq` filters and paging. [Bulk operations](https://php-sso.github.io/scim-server/bulk/) (`POST /Bulk`, with `bulkId` references and `failOnErrors`).
  No sorting or ETags (`/ServiceProviderConfig` says so).
