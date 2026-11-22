# Changelog

All notable changes are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/) and the project follows [Semantic Versioning](https://semver.org/).

## Unreleased

- Bulk operations (RFC 7644 section 3.7): `POST /Bulk` runs up to 100 creates, replaces, patches and deletes of users and groups in one request
  (1 MiB at most), with `bulkId` references between operations (also to later ones), `failOnErrors`, and a status and error for each operation.
  Each operation runs the same code as the single endpoint and is logged like it. `/ServiceProviderConfig` now says `bulk.supported: true`.
- Not breaking: nothing to wire; the single endpoints behave as before.

## 1.0.0 - 2026-11-21

First release.

- SCIM 2.0 server (RFC 7643 and RFC 7644) for PSR-7 applications: Users and Groups with `GET`, `POST`, `PUT`, `PATCH` and
  `DELETE`, `eq` filters and paging.
- A client manages only what it created (or took over, when allowed) and never an administrator.
- Accounts, groups, storage and the request log sit behind interfaces you implement.
