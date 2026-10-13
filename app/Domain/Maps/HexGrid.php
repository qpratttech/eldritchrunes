<?php

namespace App\Domain\Maps;

class HexGrid
{
    /**
     * @param  array<int, string>  $blocked
     */
    public static function crosses(int $fromX, int $fromY, int $toX, int $toY, array $blocked, bool $includeDestination = true, bool $square = false): bool
    {
        if ($square) {
            return self::crossesSquare($fromX, $fromY, $toX, $toY, $blocked, $includeDestination);
        }
        $blocked = array_flip($blocked);
        $steps = max(self::distance($fromX, $fromY, $toX, $toY), 1);
        $last = $includeDestination ? $steps : $steps - 1;
        for ($i = 1; $i <= $last; $i++) {
            $col = (int) round($fromX + (($toX - $fromX) * $i) / $steps);
            $row = (int) round($fromY + (($toY - $fromY) * $i) / $steps);
            if (isset($blocked[$col.','.$row])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $geometry
     * @return array<int, string>
     */
    public static function movementCells(array $geometry): array
    {
        return array_values(array_unique(array_merge(
            $geometry['move'] ?? [],
            array_keys($geometry['terrain'] ?? []),
        )));
    }

    /**
     * @param  array<string, mixed>  $geometry
     * @return array<int, string>
     */
    public static function sightCells(array $geometry): array
    {
        return array_values(array_unique(array_merge(
            $geometry['los'] ?? [],
            array_keys($geometry['terrain'] ?? []),
        )));
    }

    public static function distance(int $fromX, int $fromY, int $toX, int $toY): int
    {
        [$x1, $y1, $z1] = self::cube($fromX, $fromY);
        [$x2, $y2, $z2] = self::cube($toX, $toY);

        return max(abs($x1 - $x2), abs($y1 - $y2), abs($z1 - $z2));
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function cube(int $col, int $row): array
    {
        $x = $col - intdiv($row - ($row & 1), 2);
        $z = $row;

        return [$x, -$x - $z, $z];
    }

    /**
     * @param  array<int, string>  $blocked
     */
    private static function crossesSquare(int $fromX, int $fromY, int $toX, int $toY, array $blocked, bool $includeDestination): bool
    {
        $blocked = array_flip($blocked);
        $x = $fromX;
        $y = $fromY;
        $dx = abs($toX - $fromX);
        $dy = abs($toY - $fromY);
        $sx = $fromX < $toX ? 1 : -1;
        $sy = $fromY < $toY ? 1 : -1;
        $err = $dx - $dy;
        $cells = [];
        while ($x !== $toX || $y !== $toY) {
            $e2 = 2 * $err;
            if ($e2 > -$dy) {
                $err -= $dy;
                $x += $sx;
            }
            if ($e2 < $dx) {
                $err += $dx;
                $y += $sy;
            }
            $cells[] = $x.','.$y;
        }
        $last = $includeDestination ? count($cells) : max(0, count($cells) - 1);
        for ($i = 0; $i < $last; $i++) {
            if (isset($blocked[$cells[$i]])) {
                return true;
            }
        }

        return false;
    }
}
