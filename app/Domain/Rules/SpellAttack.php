<?php

namespace App\Domain\Rules;

class SpellAttack
{
    /**
     * The saving throw that resolves this spell, when the spell itself forces one.
     * A direct spell attack stays an attack roll even if a later effect also calls for a save.
     */
    public static function forcedSave(?string $rules): ?string
    {
        $text = trim(str_replace(['\\', '**'], '', (string) $rules));
        if ($text === '' || stripos($text, 'direct spell attack') !== false) {
            return null;
        }
        if (! preg_match('/^(?:The target makes an? |Each (?:target|creature)s? makes an? )?(Willpower|Reflexes|Endurance|Athletics) save\b/i', $text, $match)) {
            return null;
        }

        return ucfirst(strtolower($match[1]));
    }

    public static function damage(?string $rules): int
    {
        $text = str_replace('\\', '', (string) $rules);
        if (preg_match('/\b(?:takes?|deals?) (\d+)(?: [\p{L}]+)? damage/iu', $text, $match)) {
            return (int) $match[1];
        }

        return 0;
    }
}
