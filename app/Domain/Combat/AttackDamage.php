<?php

namespace App\Domain\Combat;

class AttackDamage
{
    public static function wounds(int $base, bool $heavy, bool $critical, bool $twoHandedMelee, bool $lesser, ?int $lesserRoll, bool $armorSaved): int
    {
        $damage = max(0, $base);
        if ($heavy) {
            $damage += 1;
        }
        if ($critical) {
            $damage += $twoHandedMelee ? 2 : 1;
        }
        if ($lesser && ($lesserRoll === null || $lesserRoll > 33)) {
            $damage = 0;
        }
        if ($armorSaved) {
            $damage = max(0, $damage - 2);
        }

        return $damage;
    }
}
