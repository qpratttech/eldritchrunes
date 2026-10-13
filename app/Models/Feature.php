<?php

namespace App\Models;

use App\Models\Concerns\VisibleTo;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Feature extends Model
{
    use VisibleTo;

    protected $guarded = [];
    protected $casts = [
        'effects_json' => 'array',
        'prerequisites_json' => 'array',
        'choices_schema_json' => 'array',
    ];

    protected function rulesText(): Attribute
    {
        return Attribute::get(fn (?string $value) => self::readable($value));
    }

    protected function flavorText(): Attribute
    {
        return Attribute::get(fn (?string $value) => self::readable($value));
    }

    public static function readable(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $value = preg_replace('/\\\\(?=\S)/u', '', $value) ?? $value;

        return str_replace('**', '', $value);
    }
}
