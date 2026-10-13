<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreatureInstance extends Model
{
    protected $guarded = [];
    protected $casts = [
        'overrides_json' => 'array',
    ];

    public function template(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(CreatureTemplate::class, 'creature_template_id'); }
    public function conditions(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(CombatantCondition::class, 'creature_instance_id'); }
}
