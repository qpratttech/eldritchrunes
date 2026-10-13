<?php

namespace App\Domain\Maps;

class MapBackgrounds
{
    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return ['field', 'dungeon', 'grass', 'stone', 'wastes', 'parchment'];
    }

    /**
     * @return array<string, string>
     */
    public static function files(): array
    {
        return [
            'field' => 'field.jpg',
            'dungeon' => 'dungeon.jpg',
            'grass' => 'grass.jpg',
            'stone' => 'stone.jpg',
            'wastes' => 'wastes.jpg',
            'parchment' => 'parchment.jpg',
        ];
    }
}
