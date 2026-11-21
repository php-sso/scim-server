# Changelog

All notable changes are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/) and the project follows [Semantic Versioning](https://semver.org/).

## 1.0.0 - 2026-11-21

First release.

- SCIM 2.0 server (RFC 7643 and RFC 7644) for PSR-7 applications: Users and Groups with `GET`, `POST`, `PUT`, `PATCH` and
  `DELETE`, `eq` filters and paging.
- A client manages only what it created (or took over, when allowed) and never an administrator.
- Accounts, groups, storage and the request log sit behind interfaces you implement.
