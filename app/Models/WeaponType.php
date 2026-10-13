<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeaponType extends Model
{
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = [
        'properties_json' => 'array',
    ];

    public function item(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Item::class); }
    public function skill(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Skill::class, 'attack_skill_id'); }
    public function techniques(): \Illuminate\Database\Eloquent\Relations\BelongsToMany { return $this->belongsToMany(Technique::class, 'weapon_type_techniques'); }
}
