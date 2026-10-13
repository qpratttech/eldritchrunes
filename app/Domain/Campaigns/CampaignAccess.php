<?php

namespace App\Domain\Campaigns;

use App\Models\Campaign;
use App\Models\User;

class CampaignAccess
{
    public static function view(User $user, Campaign $campaign): void
    {
        if (! self::isMember($user, $campaign)) {
            abort(403);
        }
    }

    public static function manage(User $user, Campaign $campaign): void
    {
        if (! self::isGm($user, $campaign)) {
            abort(403);
        }
    }

    public static function gm(User $user, Campaign $campaign): bool
    {
        return self::isGm($user, $campaign);
    }

    public static function isGm(User $user, Campaign $campaign): bool
    {
        return $campaign->owner_id === $user->id
            || $campaign->members()->where('user_id', $user->id)->where('role', 'gm')->exists();
    }

    public static function isMember(User $user, Campaign $campaign): bool
    {
        return $campaign->owner_id === $user->id
            || $campaign->members()->where('user_id', $user->id)->exists();
    }
}
