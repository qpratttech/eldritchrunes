<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterSkill extends Model
{
    protected $guarded = [];
    public $timestamps = false;

    public function skill(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Skill::class); }
}
