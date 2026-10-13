<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MagicOrder extends Model
{
    protected $guarded = [];

    public function castingSkill(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Skill::class, 'casting_skill_id'); }
    public function spells(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(Spell::class, 'order_id'); }
}
