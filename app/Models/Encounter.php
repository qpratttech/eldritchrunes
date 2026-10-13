<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Encounter extends Model
{
    protected $guarded = [];

    public function campaign(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Campaign::class); }
    public function combatants(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(Combatant::class); }
    public function events(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(CombatEvent::class); }
    public function activeCombatant(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Combatant::class, 'active_combatant_id'); }
}
