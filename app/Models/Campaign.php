<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Campaign extends Model
{
    protected $guarded = [];

    protected $hidden = ['password'];

    protected $casts = [
        'is_public' => 'boolean',
    ];

    public function owner(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
    public function members(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(CampaignMember::class); }
    public function scenes(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(Scene::class); }
    public function encounters(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(Encounter::class); }
    public function creatures(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(CreatureInstance::class); }
}
