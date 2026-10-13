<?php

namespace App\Domain\Combat;

class AttackLog
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function sentence(array $payload): string
    {
        $attacker = $payload['attacker'] ?? $payload['npc'] ?? 'Someone';
        $target = $payload['target'] ?? null;
        $weapon = $payload['attack'] ?? $payload['weapon'] ?? 'an attack';
        $line = $target
            ? "{$attacker} attacked {$target} with {$weapon}."
            : "{$attacker} attacked with {$weapon}.";
        $modifier = self::circumstance((int) ($payload['circumstance'] ?? 0));
        if ($modifier !== '') {
            $line .= ' '.$modifier.'.';
        }
        if (is_numeric($payload['percent'] ?? null)) {
            $line .= " ({$payload['percent']}% chance to hit.";
            $ranks = (int) ($payload['attacker_suffering'] ?? 0);
            if ($ranks > 0) {
                $line .= " Suffering {$ranks}.";
            }
            $line .= ')';
        }
        if (is_numeric($payload['roll'] ?? null)) {
            $line .= ' Rolled '.$payload['roll'].'.';
        } elseif (! empty($payload['display']) && $payload['display'] !== '—') {
            $line .= ' Rolled '.$payload['display'].'.';
        }
        $line .= self::luckClause($payload);
        if (! empty($payload['save'])) {
            $who = $payload['target'] ?? 'The target';
            $skillName = $payload['save_skill'] ?? 'saving throw';
            $check = is_array($payload['save_check'] ?? null) ? $payload['save_check'] : [];
            $line .= " {$who} makes a {$skillName} save.";
            if (is_numeric($check['percent'] ?? null)) {
                $line .= " ({$check['percent']}% chance.)";
            }
            if (is_numeric($check['roll'] ?? null)) {
                $line .= ' Rolled '.$check['roll'].'.';
            } elseif (($check['display'] ?? null) && $check['display'] !== '—') {
                $line .= ' Rolled '.$check['display'].'.';
            }
            if (($check['success'] ?? null) === true) {
                return $line.' Success.';
            }
            if (($check['success'] ?? null) === false) {
                $line .= ' Failure.';
            } elseif ($check === []) {
                return $line;
            }
            $line .= self::armorClause($payload);
            if ($target && array_key_exists('damage', $payload) && $payload['damage'] !== null && (int) $payload['damage'] > 0) {
                $line .= " {$target} took {$payload['damage']} damage.";
            }
        } elseif (($payload['success'] ?? null) === true) {
            $line .= ! empty($payload['critical']) ? ' Critical hit!' : ' Hit!';
            $line .= self::lesserClause($payload);
            $line .= self::armorClause($payload);
            if ($target && array_key_exists('damage', $payload) && $payload['damage'] !== null) {
                $line .= " {$target} took {$payload['damage']} damage.";
            }
        } elseif (($payload['success'] ?? null) === false) {
            $line .= ' Miss!';
        }
        if (is_array($payload['endurance'] ?? null)) {
            $check = $payload['endurance'];
            $who = $payload['target'] ?? 'The target';
            $line .= " {$who} makes an Endurance check.";
            if (is_numeric($check['percent'] ?? null)) {
                $line .= " ({$check['percent']}% chance.)";
            }
            if (is_numeric($check['roll'] ?? null)) {
                $line .= ' Rolled '.$check['roll'].'.';
            }
            if (($check['success'] ?? null) === true) {
                $line .= ' Success.';
            } elseif (($check['success'] ?? null) === false) {
                $line .= ' Failure.';
                if (isset($payload['suffering'])) {
                    $line .= ' Suffering '.$payload['suffering'].'.';
                }
            }
        }
        if (! empty($payload['defeated'])) {
            $line .= ' '.$payload['defeated'].' has been defeated.';
        }

        return $line;
    }

    /**
     * A Lesser unarmed attack wounds only on a roll of 01–33.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function lesserClause(array $payload): string
    {
        if (empty($payload['lesser']) || ! is_numeric($payload['lesser_roll'] ?? null)) {
            return '';
        }
        $roll = (int) $payload['lesser_roll'];
        $line = ' Lesser Attack. (33% chance to wound. Rolls 01–33.) Rolled '.$roll.'.';
        $line .= $roll <= 33
            ? ' The attack wounds.'
            : ' The attack deals no wounds.';

        return $line;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function armorClause(array $payload): string
    {
        if (! is_numeric($payload['armor_roll'] ?? null)) {
            return '';
        }
        $who = $payload['target'] ?? 'The target';
        $line = '';
        if (! empty($payload['armor_capped'])) {
            $line .= ' Armor Piercing caps the Armor Save at 10%.';
        } elseif (! empty($payload['rune_armor'])) {
            $line .= ' Rune Armor prevents Armor Piercing.';
        }
        $chance = $payload['armor_save'] ?? null;
        $line .= " {$who} makes an Armor Save.";
        if (is_numeric($chance)) {
            $line .= " ({$chance}% chance.)";
        }
        $line .= ' Rolled '.$payload['armor_roll'].'.';
        $line .= ! empty($payload['armor_saved'])
            ? ' The armor holds and prevents 2 wounds.'
            : ' The armor fails.';

        return $line;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function luckClause(array $payload): string
    {
        if (empty($payload['reversed']) || ! is_numeric($payload['original_roll'] ?? null) || ! is_numeric($payload['roll'] ?? null)) {
            return '';
        }

        return ' Luck reverses '.$payload['original_roll'].' to '.$payload['roll'].'.';
    }

    public static function circumstance(int $value): string
    {
        return match ($value) {
            2 => 'Greater Enhancement',
            1 => 'Enhancement',
            -1 => 'Diminishment',
            -2 => 'Greater Diminishment',
            default => '',
        };
    }

    /**
     * @return list<'major'|'minor'>
     */
    public static function actionsFromCost(?string $cost): array
    {
        $cost = strtolower((string) $cost);
        $actions = [];
        if (str_contains($cost, 'major')) {
            $actions[] = 'major';
        }
        if (str_contains($cost, 'minor')) {
            $actions[] = 'minor';
        }

        return $actions !== [] ? $actions : ['minor'];
    }
}
