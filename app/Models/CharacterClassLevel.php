<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterClassLevel extends Model
{
    protected $guarded = [];
    public $timestamps = false;

    public function heroClass(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(HeroClass::class, 'class_id'); }
}
