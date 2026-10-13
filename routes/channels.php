<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('campaign.{campaignId}', function ($user, $campaignId) {
    $campaign = \App\Models\Campaign::find($campaignId);

    return $campaign && \App\Domain\Campaigns\CampaignAccess::isMember($user, $campaign);
});
