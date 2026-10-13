<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdvancementThreshold extends Model
{
    protected $guarded = [];
    public $incrementing = false;
        protected $primaryKey = 'character_level';
        public $timestamps = false;
}
