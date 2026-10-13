<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Combatant extends Model
{
    protected $guarded = [];
    protected $casts = [
        'state_json' => 'array',
    ];

    public function encounter(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Encounter::class); }
    public function character(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Character::class); }
    public function creature(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(CreatureInstance::class, 'creature_instance_id'); }
    public function controller(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(User::class, 'controller_user_id'); }
    public function turns(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(CombatTurn::class); }
}
