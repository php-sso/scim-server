<?php

declare(strict_types=1);

namespace PhpSso\ScimServer;

/** What a DELETE from a provisioning client does to a person's account. */
enum OnDelete: string
{
    /** Lock the account and end its sessions; the person can come back when the client reactivates them. */
    case Deactivate = 'deactivate';

    /** Remove the account. */
    case Delete = 'delete';
}
