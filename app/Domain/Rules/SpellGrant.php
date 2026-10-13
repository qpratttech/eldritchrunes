<?php

namespace App\Domain\Rules;

class SpellGrant
{
    /** @var list<string> */
    public const ORDERS = [
        'Circle',
        'Elemental',
        'Forbidden',
        'Mythic',
        'Nature',
        'Necromancy',
        'Psionic',
        'Runic',
        'Shadow',
        'Shamanism',
        'Spirit',
    ];

    /**
     * A feature that says "learn up to N spells" lets the character choose them.
     * The Magus background's two tier-0 spells are handled separately.
     *
     * @return array{count: int, orders: list<string>|null, choose_order: bool, all_orders: bool, max_tier: int|null}|null
     */
    public static function fromText(?string $text): ?array
    {
        $text = str_replace('\\', '', (string) $text);
        if (preg_match('/learn two tier[ -]?0 spells/i', $text)) {
            return null;
        }
        if (! preg_match('/learn up to (one|two|three|four|five|\d+) /i', $text, $countMatch)) {
            return null;
        }
        $count = self::number($countMatch[1]);
        if (preg_match('/learn up to (?:one|two|three|four|five|\d+) ([A-Za-z]+) spells/i', $text, $orderMatch)) {
            $order = self::order($orderMatch[1]);
            if ($order) {
                return self::grant($count, [$order], false, false, self::maxTier($text));
            }
        }
        if (preg_match('/spells from (?:any|every)/i', $text) || preg_match('/access to every order/i', $text)) {
            return self::grant($count, null, false, true, self::maxTier($text));
        }
        if (preg_match('/choose one order/i', $text) || preg_match('/one order of magic and learn up to/i', $text) || preg_match('/spells from (?:that order|it)\b/i', $text)) {
            return self::grant($count, null, true, false, self::maxTier($text));
        }

        return null;
    }

    public static function choosesOrder(?string $text): bool
    {
        $text = (string) $text;
        if (preg_match('/gain access to one order of magic|choose one order/i', $text)) {
            return true;
        }

        return (bool) (self::fromText($text)['choose_order'] ?? false);
    }

    /**
     * @param  list<string>|null  $orders
     * @return array{count: int, orders: list<string>|null, choose_order: bool, all_orders: bool, max_tier: int|null}
     */
    private static function grant(int $count, ?array $orders, bool $chooseOrder, bool $allOrders, ?int $maxTier = null): array
    {
        return [
            'count' => $count,
            'orders' => $orders,
            'choose_order' => $chooseOrder,
            'all_orders' => $allOrders,
            'max_tier' => $maxTier,
        ];
    }

    private static function maxTier(string $text): ?int
    {
        if (preg_match('/(?:level|tier)[ -]?(\d+)\s+spells/i', $text, $match)) {
            return (int) $match[1];
        }

        return null;
    }

    private static function number(string $raw): int
    {
        return match (strtolower($raw)) {
            'one' => 1,
            'two' => 2,
            'three' => 3,
            'four' => 4,
            'five' => 5,
            default => (int) $raw,
        };
    }

    public static function order(string $word): ?string
    {
        foreach (self::ORDERS as $order) {
            if (strcasecmp($order, $word) === 0) {
                return $order;
            }
        }

        return null;
    }
}
