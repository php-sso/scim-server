---
title: "Bulk operations"
weight: 4
lead: "Many changes in one request, as RFC 7644 section 3.7 describes."
---

`POST /scim/v2/Bulk` takes a list of operations (create, replace, patch, delete of users and groups) and does them in one request. Providers
use it to load many people at once. Nothing needs wiring: it is on whenever the controller is, and `/ServiceProviderConfig` says so.

```json
{
  "schemas": ["urn:ietf:params:scim:api:messages:2.0:BulkRequest"],
  "failOnErrors": 5,
  "Operations": [
    {"method": "POST", "path": "/Users", "bulkId": "alice", "data": {"userName": "alice@acme.com"}},
    {"method": "POST", "path": "/Groups", "bulkId": "team", "data": {"displayName": "Engineering", "members": [{"value": "bulkId:alice"}]}},
    {"method": "PATCH", "path": "/Users/3f1a...", "data": {"Operations": [{"op": "replace", "path": "active", "value": false}]}},
    {"method": "DELETE", "path": "/Users/9b2c..."}
  ]
}
```

The answer lists each operation with its `status` (as text), the `location` of what was made, and, for a failed one, a `response` with the SCIM
error (`status`, `scimType`, `detail`):

```json
{"schemas": ["urn:ietf:params:scim:api:messages:2.0:BulkResponse"], "Operations": [
  {"method": "POST", "bulkId": "alice", "location": "https://sso.example.com/scim/v2/Users/…", "status": "201"},
  {"method": "POST", "bulkId": "team", "status": "409", "response": {"schemas": ["urn:ietf:params:scim:api:messages:2.0:Error"], "status": "409", "scimType": "uniqueness", "detail": "…"}}
]}
```

## What it does

- **The same rules as a single request.** Each operation runs the code of the matching single endpoint, so a bulk operation can do nothing a
  single one cannot: a client still manages only its own people and groups, domains and uniqueness are checked, and a delete does what the client's
  setting says. Each operation is also written to the request log as if it had been sent alone, next to a line for the bulk itself
  (`2 bulk operations, 1 failed`).
- **Not atomic.** Operations run one after the other. One that fails is reported and the others stay done. `failOnErrors` (a whole number of
  1 or more) stops after that many errors, and what was not reached is left out of the answer.
- **`bulkId` references.** A POST needs a `bulkId`, unique in the request. Anywhere else in the request, the whole value `bulkId:alice` (in `data`,
  such as a group's `members[].value`, or as the id in a `path`, such as `/Users/bulkId:alice`) is replaced by the id that POST created. An operation that refers to
  a later one waits for it, so the order you send does not matter.
  - A reference to a bulkId that is not in the request: `400`, and that operation is not done.
  - An operation whose referenced operation failed: `409`, not done.
  - A circle (A needs B, B needs A): `409` for the operations in it, not done.
- **A bad operation is a bad operation, not a bad request.** An unknown method or path, a POST without a `bulkId`, a PUT without `data` and the like
  fail with `400` in the answer; the rest of the bulk goes on. A request that cannot be read at all (no BulkRequest schema, no operations, a bad
  `failOnErrors`) is refused as a whole.
- **Limits.** At most **100 operations** (`ScimBulk::MAX_OPERATIONS`) and **1 MiB** (`ScimBulk::MAX_PAYLOAD`) per request; more is refused with `413`
  (`scimType` `tooMany` for the number). A single, non-bulk request is still limited to 256 KiB. Operations run inside one request, so keep the web
  server's time limit and the size of your largest bulk in mind.
- **Answered in the order sent**, whatever order they were run in.
- **Not supported:** `version` (there are no ETags), and GET inside a bulk (RFC 7644 allows only the four writing methods).
