<?php

namespace Tarot\Service;

use Tarot\Config\Env;
use Tarot\Structure\User;

/**
 * Which accounts the API may demote or delete. Two rules:
 *
 * - No admin removes their own admin flag or deletes their own account from the
 *   user-management page (DELETE /account is the way to leave). One wrong click
 *   there would lock an admin out, and with the last admin gone only
 *   scripts/make_admin.php on the server could undo it.
 * - An account whose email is in PROTECTED_ADMIN_EMAILS (comma-separated, any
 *   case) can't be demoted or deleted through the API by anyone, itself
 *   included, so the site's owners stay admins whatever another admin account
 *   does. Its password can still be changed.
 *
 * The API has no way to deactivate an account, so there is nothing to refuse
 * there.
 */
final readonly class AccountProtection
{
    /** Whether the account's email is listed in PROTECTED_ADMIN_EMAILS. */
    public function isProtected(User $user): bool
    {
        $protected = array_map(strtolower(...), Env::commaSeparated('PROTECTED_ADMIN_EMAILS'));

        return in_array(strtolower(trim($user->email)), $protected, true);
    }

    /** Why admin $adminId may not remove $target's admin flag, or null when they may. */
    public function refuseDemotion(int $adminId, User $target): ?string
    {
        if ($target->user_id === $adminId) {
            return "You can't remove your own admin access. Another admin can.";
        }
        if ($this->isProtected($target)) {
            return "This account is protected: its admin access can't be removed.";
        }

        return null;
    }

    /** Why admin $adminId may not delete $target's account, or null when they may. */
    public function refuseAdminDeletion(int $adminId, User $target): ?string
    {
        if ($target->user_id === $adminId) {
            return "You can't delete your own account here. Use Account Settings to delete it.";
        }

        return $this->refuseSelfDeletion($target);
    }

    /** Why $user may not delete their own account, or null when they may. */
    public function refuseSelfDeletion(User $user): ?string
    {
        return $this->isProtected($user) ? "This account is protected and can't be deleted." : null;
    }
}
