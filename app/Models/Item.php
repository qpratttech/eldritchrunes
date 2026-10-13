<?php

namespace App\Models;

use App\Models\Concerns\VisibleTo;
use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    use VisibleTo;

    protected $guarded = [];
    protected $casts = [
        'stackable' => 'boolean',
        'effects_json' => 'array',
    ];

    public function weapon(): \Illuminate\Database\Eloquent\Relations\HasOne { return $this->hasOne(WeaponType::class); }
    public function armor(): \Illuminate\Database\Eloquent\Relations\HasOne { return $this->hasOne(ArmorType::class); }
}
