<?php

namespace App\Domain\Combat;

use App\Models\Character;
use App\Models\CharacterResource;
use App\Models\CombatTurn;
use App\Models\Combatant;
use App\Models\CreatureInstance;
use App\Models\CreatureTemplate;
use App\Models\Encounter;
use Illuminate\Validation\ValidationException;

class ActionPool
{
    public const MAJOR_MAX = 1;

    public const MINOR_MAX = 2;

    /**
     * @return array{actions: list<'major'|'minor'>, major: int, minor: int, extra_used: int}
     */
    public static function price(int $major, int $minor, int $extraUsed, int $extraAllowed, bool $heavy): array
    {
        $major = max(0, min(self::MAJOR_MAX, $major));
        $minor = max(0, min(self::MINOR_MAX, $minor));
        if ($heavy) {
            if ($major < 1 || $minor < 1) {
                throw ValidationException::withMessages(['action' => 'Not enough actions to do that.']);
            }

            return ['actions' => ['major', 'minor'], 'major' => $major - 1, 'minor' => $minor - 1, 'extra_used' => $extraUsed];
        }
        if ($major >= 1) {
            return ['actions' => ['major'], 'major' => $major - 1, 'minor' => $minor, 'extra_used' => $extraUsed];
        }
        if ($extraUsed < $extraAllowed && $minor >= 1) {
            return ['actions' => ['minor'], 'major' => $major, 'minor' => $minor - 1, 'extra_used' => $extraUsed + 1];
        }

        throw ValidationException::withMessages(['action' => 'Not enough actions to do that.']);
    }

    public static function attackShift(int $already): int
    {
        return $already <= 0 ? 0 : ($already === 1 ? -1 : -2);
    }

    public static function noteCharacterAttack(Character $character): int
    {
        $already = (int) CharacterResource::query()
            ->where('character_id', $character->id)
            ->where('resource_key', 'attacks_made')
            ->value('current_value');
        self::putCharacter($character, 'attacks_made', 9, $already + 1);

        return self::attackShift($already);
    }

    public static function noteCreatureAttack(CreatureInstance $instance): int
    {
        $overrides = $instance->overrides_json ?? [];
        $already = (int) ($overrides['attacks_made'] ?? 0);
        $overrides['attacks_made'] = $already + 1;
        $instance->update(['overrides_json' => $overrides]);

        return self::attackShift($already);
    }

    public static function noteTemplateAttack(CreatureTemplate $template): int
    {
        $stats = $template->stats_json ?? [];
        $already = (int) ($stats['attacks_made'] ?? 0);
        $stats['attacks_made'] = $already + 1;
        $template->update(['stats_json' => $stats]);

        return self::attackShift($already);
    }

    public static function extraAttacksIn(?string $text): int
    {
        $text = (string) $text;
        if ($text === '' || preg_match('/beast companion you control|does not grant extra attacks/i', $text)) {
            return 0;
        }

        return preg_match('/\b(?:you may make|make) one additional\b.{0,80}\battack\b/i', $text) ? 1 : 0;
    }

    public static function spendCharacter(Character $character, bool $heavy): array
    {
        if (self::isDefeated($character)) {
            throw ValidationException::withMessages(['action' => $character->name.' is defeated and cannot act.']);
        }
        $priced = self::price(
            self::characterValue($character, 'major_action', self::MAJOR_MAX),
            self::characterValue($character, 'minor_action', self::MINOR_MAX),
            self::characterValue($character, 'extra_attacks_used', 0),
            self::characterExtras($character),
            $heavy,
        );
        self::writeCharacter($character, $priced['major'], $priced['minor'], $priced['extra_used']);

        return $priced;
    }

    public static function spendCreature(CreatureInstance $instance, bool $heavy): array
    {
        $instance->loadMissing('template');
        $overrides = $instance->overrides_json ?? [];
        $priced = self::price(
            (int) ($overrides['major_action'] ?? self::MAJOR_MAX),
            (int) ($overrides['minor_action'] ?? self::MINOR_MAX),
            (int) ($overrides['extra_attacks_used'] ?? 0),
            self::creatureExtras($instance->template),
            $heavy,
        );
        $overrides['major_action'] = $priced['major'];
        $overrides['minor_action'] = $priced['minor'];
        $overrides['extra_attacks_used'] = $priced['extra_used'];
        $instance->update(['overrides_json' => $overrides]);
        self::syncCombatant(null, $instance->id, $priced['major'], $priced['minor']);

        return $priced;
    }

    public static function spendTemplate(CreatureTemplate $template, bool $heavy): array
    {
        $stats = $template->stats_json ?? [];
        $priced = self::price(
            (int) ($stats['major_action'] ?? self::MAJOR_MAX),
            (int) ($stats['minor_action'] ?? self::MINOR_MAX),
            (int) ($stats['extra_attacks_used'] ?? 0),
            self::creatureExtras($template),
            $heavy,
        );
        $stats['major_action'] = $priced['major'];
        $stats['minor_action'] = $priced['minor'];
        $stats['extra_attacks_used'] = $priced['extra_used'];
        $template->update(['stats_json' => $stats]);

        return $priced;
    }

    /**
     * @return array{major: int, minor: int}
     */
    public static function takeOne(Combatant $combatant, string $kind): array
    {
        $combatant->loadMissing(['character', 'creature']);
        if (! in_array($kind, ['major', 'minor'], true)) {
            throw ValidationException::withMessages(['action' => 'Use a major or minor action.']);
        }
        if ($combatant->character) {
            if (self::isDefeated($combatant->character)) {
                throw ValidationException::withMessages(['action' => $combatant->character->name.' is defeated and cannot act.']);
            }
            $major = self::characterValue($combatant->character, 'major_action', self::MAJOR_MAX);
            $minor = self::characterValue($combatant->character, 'minor_action', self::MINOR_MAX);
            self::requireOne($kind, $major, $minor);
            $kind === 'major' ? $major-- : $minor--;
            $extra = self::characterValue($combatant->character, 'extra_attacks_used', 0);
            self::writeCharacter($combatant->character, $major, $minor, $extra);

            return ['major' => $major, 'minor' => $minor];
        }
        if ($combatant->creature) {
            $overrides = $combatant->creature->overrides_json ?? [];
            $major = max(0, min(self::MAJOR_MAX, (int) ($overrides['major_action'] ?? self::MAJOR_MAX)));
            $minor = max(0, min(self::MINOR_MAX, (int) ($overrides['minor_action'] ?? self::MINOR_MAX)));
            self::requireOne($kind, $major, $minor);
            $kind === 'major' ? $major-- : $minor--;
            $overrides['major_action'] = $major;
            $overrides['minor_action'] = $minor;
            $combatant->creature->update(['overrides_json' => $overrides]);

            return ['major' => $major, 'minor' => $minor];
        }

        throw ValidationException::withMessages(['action' => 'That combatant has no action pool.']);
    }

    public static function refreshCharacter(Character $character): void
    {
        if (self::isDefeated($character)) {
            self::writeCharacter($character, 0, 0, 0);
            self::putCharacter($character, 'attacks_made', 9, 0);

            return;
        }
        self::writeCharacter($character, self::MAJOR_MAX, self::MINOR_MAX, 0);
        self::putCharacter($character, 'attacks_made', 9, 0);
    }

    public static function defeat(Character $character): void
    {
        self::writeCharacter($character, 0, 0, 0);
        self::putCharacter($character, 'attacks_made', 9, 0);
    }

    public static function defeatCreature(CreatureInstance $instance): void
    {
        $overrides = $instance->overrides_json ?? [];
        $overrides['major_action'] = 0;
        $overrides['minor_action'] = 0;
        $overrides['extra_attacks_used'] = 0;
        $overrides['attacks_made'] = 0;
        $instance->update(['overrides_json' => $overrides]);
        self::syncCombatant(null, $instance->id, 0, 0);
    }

    public static function comeTo(Character $character): void
    {
        if (self::isDefeated($character) || ! $character->campaign_id) {
            return;
        }
        $encounter = Encounter::query()->where('campaign_id', $character->campaign_id)->where('status', 'active')->latest('id')->first();
        $active = $encounter ? Combatant::find($encounter->active_combatant_id) : null;
        if ($active && (int) $active->character_id === (int) $character->id) {
            self::refreshCharacter($character);
        }
    }

    public static function isDefeated(Character $character): bool
    {
        return self::wounds($character) <= 0;
    }

    public static function refreshCreature(CreatureInstance $instance): void
    {
        $overrides = $instance->overrides_json ?? [];
        $overrides['major_action'] = self::MAJOR_MAX;
        $overrides['minor_action'] = self::MINOR_MAX;
        $overrides['extra_attacks_used'] = 0;
        $overrides['attacks_made'] = 0;
        $instance->update(['overrides_json' => $overrides]);
    }

    public static function syncCharacter(Character $character): void
    {
        self::syncCombatant(
            $character->id,
            null,
            self::characterValue($character, 'major_action', self::MAJOR_MAX),
            self::characterValue($character, 'minor_action', self::MINOR_MAX),
        );
    }

    public static function characterExtras(Character $character): int
    {
        $character->loadMissing(['features.feature', 'inventory.item']);
        $total = 0;
        foreach ($character->features as $row) {
            $total += self::extraAttacksIn($row->feature?->rules_text);
        }
        $flags = $character->derivation_json['flags'] ?? [];
        $total += (int) (is_array($flags) ? ($flags['extra_attacks'] ?? 0) : 0);
        $wielded = $character->inventory->filter(fn ($row) => $row->equipped_slot === 'wielded' && $row->item?->category === 'weapon')->count();
        if ($wielded >= 2) {
            $total += 1;
        }

        return $total;
    }

    public static function creatureExtras(?CreatureTemplate $template): int
    {
        if (! $template) {
            return 0;
        }
        $total = 0;
        foreach ($template->traits_json['features'] ?? [] as $feature) {
            $total += self::extraAttacksIn(trim(($feature['name'] ?? '').' '.($feature['tip'] ?? '')));
        }

        return $total;
    }

    private static function requireOne(string $kind, int $major, int $minor): void
    {
        $remaining = $kind === 'major' ? $major : $minor;
        if ($remaining < 1) {
            throw ValidationException::withMessages(['action' => 'No '.$kind.' actions remain.']);
        }
    }

    private static function characterExtrasUsedMax(): int
    {
        return 2;
    }

    private static function wounds(Character $character): int
    {
        return (int) CharacterResource::query()->where('character_id', $character->id)->where('resource_key', 'wounds')->value('current_value');
    }

    private static function characterValue(Character $character, string $key, int $fallback): int
    {
        $row = CharacterResource::query()->where('character_id', $character->id)->where('resource_key', $key)->first();
        if (! $row) {
            return $fallback;
        }
        $max = match ($key) {
            'major_action' => self::MAJOR_MAX,
            'minor_action' => self::MINOR_MAX,
            default => self::characterExtrasUsedMax(),
        };

        return max(0, min($max, (int) $row->current_value));
    }

    private static function writeCharacter(Character $character, int $major, int $minor, int $extraUsed): void
    {
        self::putCharacter($character, 'major_action', self::MAJOR_MAX, $major);
        self::putCharacter($character, 'minor_action', self::MINOR_MAX, $minor);
        self::putCharacter($character, 'extra_attacks_used', self::characterExtrasUsedMax(), $extraUsed);
        self::syncCombatant($character->id, null, $major, $minor);
    }

    private static function putCharacter(Character $character, string $key, int $max, int $current): void
    {
        CharacterResource::query()->updateOrCreate(
            ['character_id' => $character->id, 'resource_key' => $key],
            ['base_maximum' => $max, 'current_value' => max(0, min($max, $current))],
        );
    }

    private static function syncCombatant(?int $characterId, ?int $creatureId, int $major, int $minor): void
    {
        $combatant = Combatant::query()
            ->when($characterId, fn ($query) => $query->where('character_id', $characterId))
            ->when($creatureId, fn ($query) => $query->where('creature_instance_id', $creatureId))
            ->whereHas('encounter', fn ($query) => $query->where('status', 'active'))
            ->latest('id')
            ->first();
        if (! $combatant) {
            return;
        }
        $encounter = $combatant->encounter ?? Encounter::find($combatant->encounter_id);
        if (! $encounter || $encounter->active_combatant_id !== $combatant->id) {
            return;
        }
        CombatTurn::query()
            ->where('encounter_id', $encounter->id)
            ->where('combatant_id', $combatant->id)
            ->where('round_number', $encounter->round_number)
            ->whereNull('ended_at')
            ->update([
                'major_remaining' => max(0, min(self::MAJOR_MAX, $major)),
                'minor_remaining' => max(0, min(self::MINOR_MAX, $minor)),
            ]);
    }
}
