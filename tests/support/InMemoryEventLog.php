<?php

declare(strict_types=1);

namespace PhpSso\ScimServer\Tests\Support;

use PhpSso\ScimServer\Contract\EventLog;

final class InMemoryEventLog implements EventLog
{
    /** @var list<array{client: int, method: string, path: string, status: int, detail: string}> oldest first */
    private array $events = [];

    public function record(int $clientId, string $method, string $path, int $status, string $detail): void
    {
        $this->events[] = ['client' => $clientId, 'method' => $method, 'path' => $path, 'status' => $status, 'detail' => $detail];
    }

    /** @return list<array{client: int, method: string, path: string, status: int, detail: string}> newest first */
    public function recent(int $clientId): array
    {
        return array_values(array_reverse(array_filter($this->events, static fn (array $e): bool => $e['client'] === $clientId)));
    }
}
