<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterOrderAccess extends Model
{
    protected $table = 'character_order_access';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'restrictions_json' => 'array',
    ];
}
