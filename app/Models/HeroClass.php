<?php

namespace App\Models;

use App\Models\Concerns\VisibleTo;
use Illuminate\Database\Eloquent\Model;

class HeroClass extends Model
{
    use VisibleTo;

    protected $table = 'classes';
    protected $guarded = [];
    protected $casts = [
        'progression_json' => 'array',
    ];

    public function role(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Role::class, 'starting_role_id'); }
    public function primarySkills(): \Illuminate\Database\Eloquent\Relations\BelongsToMany { return $this->belongsToMany(Skill::class, 'class_primary_skills', 'class_id', 'skill_id'); }
}
