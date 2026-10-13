<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignMember extends Model
{
    protected $guarded = [];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(User::class); }
    public function campaign(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Campaign::class); }
}
