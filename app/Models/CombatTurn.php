<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CombatTurn extends Model
{
    public $timestamps = false;

    protected $guarded = [];
    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];
}
