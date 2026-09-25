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

    /**
     * May sign in and view, but may not moderate.
     *
     * Exists so that "signed in" and "allowed to moderate" are genuinely
     * different states. Without a role that fails the check, the role check on
     * the admin routes is untestable and effectively decorative — and a future
     * account type would inherit moderation rights by default.
     */
    case Viewer = 'viewer';

    public function canModerate(): bool
    {
        return $this !== self::Viewer;
    }

    public function canAdminister(): bool
    {
        return $this === self::Admin;
    }
}
