<?php

namespace App\Domain\Rules;

class FeatureMechanics
{
    /**
     * Mechanical grants the character creator can apply.
     * Choice features record a selection instead of a fixed number.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function effects(string $name): array
    {
        $attribute = [
            'Agile' => ['agility', 1],
            'Greater Agility' => ['agility', 1],
            'Uncanny Agility' => ['agility', 2],
            'Wise' => ['intellect', 1],
            'Great Wisdom' => ['intellect', 1],
            'Brilliant' => ['intellect', 2],
            'Strong Willed' => ['resolve', 1],
            'Resolute' => ['resolve', 1],
            'Iron Willed' => ['resolve', 2],
            'Likable' => ['social', 1],
            'Charming' => ['social', 1],
            'Captivating' => ['social', 2],
            'Strong' => ['strength', 1],
            'Very Strong' => ['strength', 1],
            'Monstrous Strength' => ['strength', 2],
            'Tough' => ['vitality', 1],
            'Resilient' => ['vitality', 1],
            'Iron Hide' => ['vitality', 2],
        ];

        if (isset($attribute[$name])) {
            return [['attribute' => $attribute[$name][0], 'amount' => $attribute[$name][1]]];
        }

        return match ($name) {
            'Attribute Focus' => [['choice' => 'attribute', 'amount' => 1, 'capped' => true]],
            'Talented' => [['choice' => 'talent']],
            'Accursed' => [['choice' => 'damage_type'], ['choice' => 'extra_primary'], ['flag' => 'uncanny_weakness']],
            'Ancient Knowledge' => [['resource' => 'sorcerous_soul', 'amount' => 2]],
            'Ancient Lineage' => [['resource' => 'sorcerous_power', 'amount' => 1]],
            'Bite/Claws' => [['flag' => 'natural_weapons']],
            'Brute' => [['flag' => 'brute']],
            'Elemental Resistance' => [['choice' => 'damage_type'], ['flag' => 'uncanny_resistance']],
            'Grim Lineage' => [['skill' => 'willpower', 'amount' => 1], ['skill' => 'melee', 'amount' => 1]],
            'Horns' => [['flag' => 'horns']],
            'Hulking' => [['resource' => 'wounds', 'amount' => 1]],
            "Hunter's Lineage" => [
                ['skill' => 'survival', 'amount' => 1],
                ['skill' => 'nature_lore', 'amount' => 1],
                ['skill' => 'ranged', 'amount' => 1],
            ],
            'Lucky' => [['resource' => 'luck', 'amount' => 2]],
            'Mountain Bones' => [['resource' => 'stamina', 'amount' => 1], ['flag' => 'heavy_armor_not_slowed']],
            'Natural Armor' => [['flag' => 'natural_armor']],
            'Nightvision' => [['flag' => 'nightvision']],
            'Ruthless Lineage' => [['flag' => 'ruthless_lineage']],
            'Fury' => [['flag' => 'fury']],
            'Skilled' => [['choice' => 'nonattack', 'amount' => 2]],
            'Stealthy' => [['flag' => 'stealth_greater_enhancement']],
            'Swift Lineage' => [['movement' => ['major' => 10, 'minor' => 5]]],
            'Versatile' => [['choice' => 'nonattack_two', 'amount' => 1]],
            'Alert' => [['resource' => 'initiative_bonus', 'amount' => 1]],
            'Blood Rage' => [['flag' => 'blood_rage']],
            'Danger Sense' => [['resource' => 'luck', 'amount' => 1]],
            'Dark Soul' => [['flag' => 'dark_soul']],
            'Hardy' => [['resource' => 'wounds', 'amount' => 1]],
            'Magus' => [['resource' => 'sorcerous_soul', 'amount' => 2], ['choice' => 'two_spells']],
            'Man-at-Arms' => [['choice' => 'technique']],
            'Peaceful' => [['flag' => 'peaceful']],
            'Regal' => [['flag' => 'social_enhanced']],
            'Rigor' => [['resource' => 'stamina', 'amount' => 1]],
            'Rowdy' => [['flag' => 'rowdy']],
            'Toil' => [['flag' => 'endurance_enhanced']],
            'Wisened' => [['flag' => 'intellect_enhanced']],
            'Foretold' => [['resource' => 'luck', 'amount' => 2]],
            'Sorcerous Potency' => [['resource' => 'sorcerous_power', 'amount' => 1]],
            'Otherworldly Spirit' => [['resource' => 'sorcerous_soul', 'amount' => 1]],
            'Vigor' => [['resource' => 'stamina', 'amount' => 2]],
            'Deadly' => [['resource' => 'crit_range', 'amount' => 3]],
            default => [],
        };
    }

    public static function needsChoice(string $name): ?string
    {
        foreach (self::effects($name) as $effect) {
            if (isset($effect['choice'])) {
                return $effect['choice'];
            }
        }

        return null;
    }
}
