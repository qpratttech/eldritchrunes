<?php

namespace App\Domain\Maps;

use App\Models\Scene;
use App\Models\SceneToken;
use App\Models\SceneWall;

class FogOfWar
{
    /** How far a character clears fog, in cells, when nothing blocks sight. */
    private const SIGHT = 8;

    public static function reveal(Scene $scene): void
    {
        $settings = $scene->settings_json ?? [];
        $fogged = array_fill_keys($settings['fogged'] ?? [], true);
        if (empty($settings['fog']) && $fogged === []) {
            return;
        }
        $cols = max(1, (int) ($settings['cols'] ?? 80));
        $rows = max(1, (int) ($settings['rows'] ?? 48));
        $square = ($settings['grid'] ?? 'hex') === 'square';
        $blocked = self::sightBlocks($scene);
        $explored = array_fill_keys($settings['explored'] ?? [], true);
        $tokens = SceneToken::query()->where('scene_id', $scene->id)->with('combatant')->get()
            ->filter(fn (SceneToken $token) => (bool) $token->combatant?->character_id);
        foreach ($tokens as $token) {
            $fromX = (int) $token->x;
            $fromY = (int) $token->y;
            $minX = max(0, $fromX - self::SIGHT);
            $maxX = min($cols - 1, $fromX + self::SIGHT);
            $minY = max(0, $fromY - self::SIGHT);
            $maxY = min($rows - 1, $fromY + self::SIGHT);
            for ($y = $minY; $y <= $maxY; $y++) {
                for ($x = $minX; $x <= $maxX; $x++) {
                    if (self::apart($fromX, $fromY, $x, $y, $square) > self::SIGHT) {
                        continue;
                    }
                    if (HexGrid::crosses($fromX, $fromY, $x, $y, $blocked, false, $square)) {
                        continue;
                    }
                    $key = $x.','.$y;
                    unset($fogged[$key]);
                    $explored[$key] = true;
                }
            }
        }
        $settings['explored'] = array_keys($explored);
        $settings['fogged'] = array_keys($fogged);
        $scene->update(['settings_json' => $settings]);
    }

    private static function apart(int $fromX, int $fromY, int $toX, int $toY, bool $square): int
    {
        if ($square) {
            return max(abs($fromX - $toX), abs($fromY - $toY));
        }

        return HexGrid::distance($fromX, $fromY, $toX, $toY);
    }

    /**
     * @return array<int, string>
     */
    private static function sightBlocks(Scene $scene): array
    {
        $wall = SceneWall::query()->where('scene_id', $scene->id)->where('wall_type', 'hex')->first();

        return HexGrid::sightCells($wall?->geometry_json ?? []);
    }
}
