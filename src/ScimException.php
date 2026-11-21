<?php

declare(strict_types=1);

namespace PhpSso\ScimServer;

use RuntimeException;

/** A SCIM request that cannot be done: the status, the SCIM error type (RFC 7644 section 3.12) and a sentence for the caller. */
final class ScimException extends RuntimeException
{
    public function __construct(public readonly int $status, string $detail, public readonly ?string $scimType = null)
    {
        parent::__construct($detail);
    }
}
