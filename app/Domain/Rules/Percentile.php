<?php

namespace App\Domain\Rules;

class Percentile
{
    /** @var array<int, int|null> */
    public const SUCCESS = [
        0 => 1,
        1 => 5,
        2 => 11,
        3 => 17,
        4 => 23,
        5 => 29,
        6 => 35,
        7 => 41,
        8 => 47,
        9 => 53,
        10 => 59,
        11 => 65,
        12 => 71,
        13 => 77,
        14 => 83,
        15 => 89,
        16 => 95,
        17 => 99,
    ];

    public static function tier(int $attributeStep, int $skillRank, int $circumstance = 0, int $suffering = 0, bool $broken = false): int
    {
        $circumstance = max(-2, min(2, $circumstance));

        return $attributeStep + $skillRank + $circumstance - $suffering - ($broken ? 1 : 0);
    }

    public static function successPercent(int $tier): ?int
    {
        if ($tier <= -1) {
            return null;
        }

        if ($tier >= 18) {
            return 100;
        }

        return self::SUCCESS[$tier];
    }

    public static function outcome(int $tier): string
    {
        if ($tier <= -1) {
            return 'impossible';
        }

        if ($tier >= 18) {
            return 'automatic';
        }

        return 'roll';
    }

    public static function isCritical(int $roll, int $critRangeEnd, bool $success): bool
    {
        $critRangeEnd = max(0, min(99, $critRangeEnd));

        return $success && $roll >= 1 && $roll <= $critRangeEnd;
    }

    /**
     * Swap the tens and ones digits. A roll of 100 cannot be reversed.
     */
    public static function reverseLuck(int $roll): ?int
    {
        if ($roll < 1 || $roll > 99) {
            return null;
        }

        $tens = intdiv($roll, 10);
        $ones = $roll % 10;
        $reversed = ($ones * 10) + $tens;

        return $reversed === 0 ? null : $reversed;
    }

    public static function display(int $roll): string
    {
        return $roll === 100 ? '100' : str_pad((string) $roll, 2, '0', STR_PAD_LEFT);
    }
}
