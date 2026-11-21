<?php

declare(strict_types=1);

namespace PhpSso\ScimServer;

/**
 * Reads the operations of a SCIM PATCH on a group into changes: a new name or external id, and an ordered list of member
 * operations. Providers write members in different ways (a list of objects, one object, `members[value eq "id"]` to remove
 * one person, a `remove` of `members` with no value to empty the group); all of them end up as the same three kinds of step.
 */
final class ScimGroupPatch
{
    /**
     * @param list<mixed> $operations the `Operations` of the request
     * @return array{changes: array<string, ?string>, members: list<array{op: string, subs: ?list<string>}>, ignored: list<string>}
     *         `subs` is null for "everyone" (a remove with no value); `op` is add, remove or replace
     * @throws ScimException
     */
    public static function read(array $operations): array
    {
        $changes = [];
        $members = [];
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
                foreach ($value as $key => $inner) {
                    if (is_string($key)) {
                        self::apply($changes, $members, $ignored, $op, $key, $inner);
                    }
                }
                continue;
            }
            self::apply($changes, $members, $ignored, $op, $path, $value);
        }

        return ['changes' => $changes, 'members' => $members, 'ignored' => array_values(array_unique($ignored))];
    }

    /**
     * @param array<string, ?string> $changes
     * @param list<array{op: string, subs: ?list<string>}> $members
     * @param list<string> $ignored
     */
    private static function apply(array &$changes, array &$members, array &$ignored, string $op, string $path, mixed $value): void
    {
        $lower = strtolower($path);
        if ($lower === 'displayname' || $lower === 'externalid') {
            $key = $lower === 'displayname' ? 'displayName' : 'externalId';
            if ($op === 'remove') {
                if ($key === 'externalId') {
                    $changes[$key] = null;
                } else {
                    $ignored[] = $path; // a group cannot lose its name
                }

                return;
            }
            if (!is_string($value)) {
                throw new ScimException(400, "The value of \"{$path}\" must be text.", 'invalidValue');
            }
            $changes[$key] = trim($value);

            return;
        }
        if ($lower !== 'members' && !str_starts_with($lower, 'members[')) {
            $ignored[] = $path;

            return;
        }

        if ($op === 'remove') {
            if (preg_match('/^members\[\s*value\s+eq\s+"((?:[^"\\\\]|\\\\.)*)"\s*\]$/i', $path, $m) === 1) {
                $members[] = ['op' => 'remove', 'subs' => [stripcslashes($m[1])]];
            } elseif ($value === null || $value === []) {
                $members[] = ['op' => 'remove', 'subs' => null];
            } else {
                $members[] = ['op' => 'remove', 'subs' => self::subs($value)];
            }

            return;
        }
        $members[] = ['op' => $op, 'subs' => self::subs($value)];
    }

    /**
     * The ids in a `members` value: a list of objects, one object, or ids as text.
     * @return list<string>
     * @throws ScimException
     */
    public static function subs(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (is_string($value) || (is_array($value) && array_key_exists('value', $value))) {
            $value = [$value];
        }
        if (!is_array($value)) {
            throw new ScimException(400, 'members must be a list of objects with a "value".', 'invalidValue');
        }
        $subs = [];
        foreach ($value as $entry) {
            $sub = is_string($entry) ? $entry : (is_array($entry) && is_string($entry['value'] ?? null) ? $entry['value'] : null);
            if ($sub === null || trim($sub) === '') {
                throw new ScimException(400, 'Every member needs a "value", the id of a person.', 'invalidValue');
            }
            $subs[] = trim($sub);
        }

        return $subs;
    }
}
