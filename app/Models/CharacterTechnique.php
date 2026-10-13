<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterTechnique extends Model
{
    protected $guarded = [];

    public function technique(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Technique::class);
    }

    public function weaponType(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(WeaponType::class);
    }
}
