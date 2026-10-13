<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterWallet extends Model
{
    protected $guarded = [];
    public $incrementing = false;
        protected $primaryKey = 'character_id';
        public $timestamps = false;
}
