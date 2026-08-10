<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Administrative roles. ScamSense has no public accounts.
 *
 * The column defaults to the least-privileged role, so a user created without
 * an explicit role cannot moderate or administer anything. Phase 8 checks this
 * on every admin route rather than relying on `auth` alone.
 */
enum UserRole: string
{
    /** Full access, including user management and dataset export. */
    case Admin = 'admin';

    /** May approve or reject feedback, nothing else. */
    case Moderator = 'moderator';

    public function canModerate(): bool
    {
        return true;
    }

    public function canAdminister(): bool
    {
        return $this === self::Admin;
    }
}
