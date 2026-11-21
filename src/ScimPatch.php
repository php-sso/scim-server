<?php

declare(strict_types=1);

namespace PhpSso\ScimServer;

/**
 * Reads the operations of a SCIM PATCH (RFC 7644 section 3.5.2) into plain changes to a person. Providers differ in how
 * they write them (Entra and Okta in particular): the operation in any case, a path or none with an object as the value,
 * booleans as text, an email addressed by a filter. Paths that mean nothing to us are skipped and reported, not an error.
 */
final class ScimPatch
{
    /**
     * @param list<mixed> $operations the `Operations` of the request
     * @return array{changes: array<string, mixed>, ignored: list<string>} keys of `changes`: active, userName, externalId, email, displayName, name (formatted), givenName, familyName
     * @throws ScimException
     */
    public static function read(array $operations): array
    {
        $changes = [];
        $ignored = [];
        foreach ($operations as $operation) {
            if (!is_array($operation) || !is_string($operation['op'] ?? null)) {
                throw new ScimException(400, 'Every operation needs an "op".', 'invalidSyntax');
            }
            $op = strtolower($operation['op']);
            if (!in_array($op, ['add', 'replace', 'remove'], true)) {
                throw new ScimException(400, "Unknown operation \"{$operation['op']}\".", 'invalidSyntax');
            }
            $path = isset($operation['path']) && is_string($operation['path']) ? trim($operation['path']) : '';
            $value = $operation['value'] ?? null;

            if ($path === '') {
                if (!is_array($value)) {
                    throw new ScimException(400, 'An operation without a path needs an object as its value.', 'invalidSyntax');
                }
                foreach (self::flatten($value) as $key => $inner) {
                    self::set($changes, $ignored, $op, $key, $inner);
                }
                continue;
            }
            self::set($changes, $ignored, $op, $path, $value);
        }

        return ['changes' => $changes, 'ignored' => array_values(array_unique($ignored))];
    }

    /**
     * `{"name": {"givenName": "A"}, "emails": [...]}` as path => value pairs.
     * @param array<mixed> $value
     * @return array<string, mixed>
     */
    private static function flatten(array $value): array
    {
        $flat = [];
        foreach ($value as $key => $inner) {
            if (!is_string($key)) {
                continue;
            }
            if (strtolower($key) === 'name' && is_array($inner)) {
                foreach ($inner as $part => $text) {
                    $flat['name.' . $part] = $text;
                }
                continue;
            }
            $flat[$key] = $inner;
        }

        return $flat;
    }

    /**
     * @param array<string, mixed> $changes
     * @param list<string> $ignored
     */
    private static function set(array &$changes, array &$ignored, string $op, string $path, mixed $value): void
    {
        $key = match (true) {
            strtolower($path) === 'active' => 'active',
            strtolower($path) === 'username' => 'userName',
            strtolower($path) === 'externalid' => 'externalId',
            strtolower($path) === 'displayname' => 'displayName',
            strtolower($path) === 'name.formatted' => 'name',
            strtolower($path) === 'name.givenname' => 'givenName',
            strtolower($path) === 'name.familyname' => 'familyName',
            strtolower($path) === 'emails', strtolower($path) === 'emails.value', str_starts_with(strtolower($path), 'emails[') => 'email',
            default => null,
        };
        if ($key === null) {
            $ignored[] = $path;

            return;
        }
        if ($op === 'remove') {
            if (in_array($key, ['externalId', 'displayName', 'name', 'givenName', 'familyName'], true)) {
                $changes[$key] = null;
            } else {
                $ignored[] = $path; // a person cannot lose their username, email or state
            }

            return;
        }

        if ($key === 'active') {
            $changes['active'] = self::boolean($value);

            return;
        }
        if ($key === 'email') {
            $email = self::emailFrom($value);
            if ($email === null) {
                throw new ScimException(400, 'The email value is missing or not text.', 'invalidValue');
            }
            $changes['email'] = $email;

            return;
        }
        if (!is_string($value) && $value !== null) {
            throw new ScimException(400, "The value of \"{$path}\" must be text.", 'invalidValue');
        }
        $changes[$key] = $value === null ? null : trim($value);
    }

    /**
     * True, false, or the texts some providers send for them.
     * @throws ScimException
     */
    public static function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value) && in_array(strtolower(trim($value)), ['true', 'false'], true)) {
            return strtolower(trim($value)) === 'true';
        }

        throw new ScimException(400, '"active" must be true or false.', 'invalidValue');
    }

    /** The address in an `emails` value: a list of objects (the primary or first one), one object, or plain text. */
    public static function emailFrom(mixed $value): ?string
    {
        if (is_string($value)) {
            return trim($value) === '' ? null : trim($value);
        }
        if (!is_array($value)) {
            return null;
        }
        if (isset($value['value'])) {
            return self::emailFrom($value['value']);
        }
        $first = null;
        foreach ($value as $entry) {
            $address = is_array($entry) && is_string($entry['value'] ?? null) ? trim($entry['value']) : null;
            if ($address === null || $address === '') {
                continue;
            }
            if (filter_var($entry['primary'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                return $address;
            }
            $first ??= $address;
        }

        return $first;
    }
}
