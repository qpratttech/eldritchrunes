<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterAttribute extends Model
{
    protected $guarded = [];
    public $timestamps = false;

    public function attribute(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Attribute::class); }
}
