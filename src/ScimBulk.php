<?php

declare(strict_types=1);

namespace PhpSso\ScimServer;

use Closure;

/**
 * Bulk operations (RFC 7644 section 3.7): a list of creates, replaces, patches and deletes in one request. Each operation
 * is done by `$execute`, the same code the single-resource endpoints run, so a bulk operation can do nothing a single one cannot.
 *
 * - **Not atomic.** Operations are done one after the other and the ones that fail are reported; the others stay done.
 *   `failOnErrors` stops after that many errors, and what was not reached is not in the response.
 * - **`bulkId` references.** A string `bulkId:xyz` in `data` or in the `path` is replaced by the id of the resource that
 *   the POST with that bulkId created. An operation that refers to a later one waits for it. A reference to nothing is
 *   a 400, a circle (A needs B and B needs A) or an operation whose dependency failed is a 409.
 * - **Answered in the order they were sent.** Operations that did not need a particular order may still have run in another.
 */
final class ScimBulk
{
    public const REQUEST_SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:BulkRequest';
    public const RESPONSE_SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:BulkResponse';
    public const MAX_OPERATIONS = 100;
    public const MAX_PAYLOAD = 1048576;

    private const METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];
    private const REFERENCE = 'bulkId:';

    /**
     * @param Closure(string, string, array<mixed>): array{status: int, data: array<string, mixed>|null, location: string|null} $execute
     *        does one operation (method, path such as `/Users/abc`, data) or throws a ScimException
     * @param string $baseUrl the address of the SCIM service, without the trailing slash, for the `location` of what was done
     */
    public function __construct(private Closure $execute, private string $baseUrl)
    {
    }

    /**
     * @param array<mixed> $request the parsed BulkRequest
     * @return array<string, mixed> the BulkResponse
     * @throws ScimException when the request as a whole cannot be used
     */
    public function run(array $request): array
    {
        $schemas = $request['schemas'] ?? null;
        if (!is_array($schemas) || !in_array(self::REQUEST_SCHEMA, $schemas, true)) {
            throw new ScimException(400, 'A bulk request needs "schemas" with ' . self::REQUEST_SCHEMA . '.', 'invalidSyntax');
        }
        $raw = $request['Operations'] ?? $request['operations'] ?? null;
        if (!is_array($raw) || !array_is_list($raw) || $raw === []) {
            throw new ScimException(400, 'A bulk request needs a list of "Operations" with at least one operation.', 'invalidSyntax');
        }
        if (count($raw) > self::MAX_OPERATIONS) {
            throw new ScimException(413, 'A bulk request may have at most ' . self::MAX_OPERATIONS . ' operations.', 'tooMany');
        }
        $failOnErrors = $request['failOnErrors'] ?? null;
        if ($failOnErrors !== null && (!is_int($failOnErrors) || $failOnErrors < 1)) {
            throw new ScimException(400, '"failOnErrors" must be a whole number of 1 or more.', 'invalidValue');
        }

        $operations = $this->parse($raw);
        $results = $this->execute($operations, $failOnErrors);
        ksort($results);

        return ['schemas' => [self::RESPONSE_SCHEMA], 'Operations' => array_values($results)];
    }

    /**
     * @param list<mixed> $raw
     * @return list<array{index: int, method: string, path: string, bulkId: string|null, data: array<mixed>, error: array{0: int, 1: string, 2?: string|null}|null, refs: list<string>}>
     */
    private function parse(array $raw): array
    {
        $operations = [];
        $seen = [];
        foreach ($raw as $index => $item) {
            /** @var array{0: int, 1: string, 2?: string|null}|null $error */
            $error = null;
            $item = is_array($item) ? $item : [];
            $method = is_string($item['method'] ?? null) ? strtoupper($item['method']) : '';
            $path = is_string($item['path'] ?? null) ? $item['path'] : '';
            $bulkId = is_string($item['bulkId'] ?? null) && $item['bulkId'] !== '' ? $item['bulkId'] : null;
            $data = is_array($item['data'] ?? null) ? $item['data'] : null;

            if (!in_array($method, self::METHODS, true)) {
                $error = [400, 'The method must be POST, PUT, PATCH or DELETE.', 'invalidValue'];
            } elseif (preg_match('#^/(Users|Groups)(/[^/]+)?$#', $path, $m) !== 1) {
                $error = [400, 'The path must be /Users, /Users/{id}, /Groups or /Groups/{id}.', 'invalidPath'];
            } elseif ($method === 'POST' && isset($m[2])) {
                $error = [400, 'A POST goes to /Users or /Groups, without an id.', 'invalidPath'];
            } elseif ($method !== 'POST' && !isset($m[2])) {
                $error = [400, "A {$method} needs the id of a resource in its path."];
            } elseif ($method === 'POST' && $bulkId === null) {
                $error = [400, 'A POST needs a "bulkId".', 'invalidValue'];
            } elseif ($method === 'POST' && isset($seen[$bulkId])) {
                $error = [400, "The bulkId \"{$bulkId}\" is used by more than one operation.", 'invalidValue'];
            } elseif ($method !== 'DELETE' && $data === null) {
                $error = [400, "A {$method} needs \"data\"."];
            }
            if ($method === 'POST' && $bulkId !== null) {
                $seen[$bulkId] ??= $index;
            }

            $data ??= [];
            $operations[] = [
                'index' => $index, 'method' => $method, 'path' => $path, 'bulkId' => $bulkId, 'data' => $data, 'error' => $error,
                'refs' => $error === null ? array_values(array_unique(array_merge($this->pathReferences($path), $this->dataReferences($data)))) : [],
            ];
        }

        return $operations;
    }

    /**
     * @param list<array{index: int, method: string, path: string, bulkId: string|null, data: array<mixed>, error: array{0: int, 1: string, 2?: string|null}|null, refs: list<string>}> $operations
     * @return array<int, array<string, mixed>> the answer for each operation that was reached, by index
     */
    private function execute(array $operations, ?int $failOnErrors): array
    {
        /** @var array<string, int> $creator the operation that makes each bulkId */
        $creator = [];
        foreach ($operations as $op) {
            if ($op['method'] === 'POST' && $op['bulkId'] !== null && $op['error'] === null && !isset($creator[$op['bulkId']])) {
                $creator[$op['bulkId']] = $op['index'];
            }
        }

        $results = [];
        /** @var array<string, string|null> $ids the id each bulkId got, or null when its operation failed */
        $ids = [];
        $pending = array_column($operations, null, 'index');
        $errors = 0;

        while ($pending !== []) {
            // The first operation, in the order sent, whose dependencies have all been tried.
            $next = null;
            foreach ($pending as $op) {
                $waiting = false;
                foreach ($op['refs'] as $reference) {
                    if (isset($creator[$reference]) && !array_key_exists($reference, $ids) && $creator[$reference] !== $op['index']) {
                        $waiting = true;
                        break;
                    }
                }
                if (!$waiting) {
                    $next = $op;
                    break;
                }
            }
            $circular = $next === null;
            $next ??= reset($pending);
            unset($pending[$next['index']]);

            $outcome = $circular
                ? $this->failure($next, 409, 'This operation is part of a circle of bulkId references.', 'invalidValue')
                : $this->perform($next, $creator, $ids);
            $results[$next['index']] = $outcome['result'];
            if ($next['method'] === 'POST' && $next['bulkId'] !== null && !array_key_exists($next['bulkId'], $ids) && ($creator[$next['bulkId']] ?? null) === $next['index']) {
                $ids[$next['bulkId']] = $outcome['id'];
            }
            if ($outcome['failed'] && ++$errors === $failOnErrors) {
                break;
            }
        }

        return $results;
    }

    /**
     * @param array{index: int, method: string, path: string, bulkId: string|null, data: array<mixed>, error: array{0: int, 1: string, 2?: string|null}|null, refs: list<string>} $op
     * @param array<string, int> $creator
     * @param array<string, string|null> $ids
     * @return array{result: array<string, mixed>, failed: bool, id: string|null}
     */
    private function perform(array $op, array $creator, array $ids): array
    {
        if ($op['error'] !== null) {
            return $this->failure($op, $op['error'][0], $op['error'][1], $op['error'][2] ?? null);
        }
        foreach ($op['refs'] as $reference) {
            if (!isset($creator[$reference])) {
                return $this->failure($op, 400, "Nothing in this request has the bulkId \"{$reference}\".", 'invalidValue');
            }
            if (($ids[$reference] ?? null) === null) {
                return $this->failure($op, 409, "The operation with the bulkId \"{$reference}\" did not succeed, so this one was not done.", 'invalidValue');
            }
        }

        $path = $this->substitutePath($op['path'], $ids);
        /** @var array<mixed> $data */
        $data = $this->substitute($op['data'], $ids);
        try {
            $done = ($this->execute)($op['method'], $path, $data);
        } catch (ScimException $e) {
            return $this->failure($op, $e->status, $e->getMessage(), $e->scimType);
        } catch (\Throwable $e) {
            error_log('SSO SCIM bulk: ' . $e->getMessage());

            return $this->failure($op, 500, 'The server could not do that.', null);
        }

        $result = array_filter([
            'method' => $op['method'],
            'bulkId' => $op['bulkId'],
            'location' => $done['location'] ?? ($this->baseUrl . $path),
            'status' => (string) $done['status'],
        ], static fn (mixed $v): bool => $v !== null);
        $id = is_string($done['data']['id'] ?? null) ? $done['data']['id'] : null;

        return ['result' => $result, 'failed' => false, 'id' => $id];
    }

    /**
     * @param array{index: int, method: string, path: string, bulkId: string|null, data: array<mixed>, error: array{0: int, 1: string, 2?: string|null}|null, refs: list<string>} $op
     * @return array{result: array<string, mixed>, failed: bool, id: string|null}
     */
    private function failure(array $op, int $status, string $detail, ?string $scimType): array
    {
        $response = array_filter(['schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'], 'status' => (string) $status, 'scimType' => $scimType, 'detail' => $detail], static fn (mixed $v): bool => $v !== null);
        $result = array_filter(['method' => $op['method'] !== '' ? $op['method'] : null, 'bulkId' => $op['bulkId'], 'status' => (string) $status, 'response' => $response], static fn (mixed $v): bool => $v !== null);

        return ['result' => $result, 'failed' => true, 'id' => null];
    }

    /** @return list<string> */
    private function pathReferences(string $path): array
    {
        preg_match_all('#/' . preg_quote(self::REFERENCE, '#') . '([^/]+)#', $path, $m);

        return $m[1];
    }

    /** @return list<string> every `bulkId:xyz` (the whole string) in the data, at any depth */
    private function dataReferences(mixed $value): array
    {
        if (is_string($value)) {
            return str_starts_with($value, self::REFERENCE) && strlen($value) > strlen(self::REFERENCE) ? [substr($value, strlen(self::REFERENCE))] : [];
        }
        $found = [];
        if (is_array($value)) {
            foreach ($value as $item) {
                $found = array_merge($found, $this->dataReferences($item));
            }
        }

        return $found;
    }

    /**
     * @param array<string, string|null> $ids
     * @return mixed the same value with every `bulkId:xyz` (the whole string) replaced by the id it stands for
     */
    private function substitute(mixed $value, array $ids): mixed
    {
        if (is_string($value)) {
            $name = str_starts_with($value, self::REFERENCE) ? substr($value, strlen(self::REFERENCE)) : null;

            return $name !== null && isset($ids[$name]) ? $ids[$name] : $value;
        }
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->substitute($item, $ids), $value);
        }

        return $value;
    }

    /** @param array<string, string|null> $ids */
    private function substitutePath(string $path, array $ids): string
    {
        return preg_replace_callback('#/' . preg_quote(self::REFERENCE, '#') . '([^/]+)#', static fn (array $m): string => '/' . ($ids[$m[1]] ?? $m[0]), $path) ?? $path;
    }
}
