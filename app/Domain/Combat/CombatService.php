<?php

namespace App\Domain\Combat;

use App\Domain\Rules\Percentile;
use App\Domain\Rules\SpellAttack;
use App\Events\TableUpdated;
use App\Models\Character;
use App\Models\CharacterResource;
use App\Models\CombatEvent;
use App\Models\CombatTurn;
use App\Models\Combatant;
use App\Models\CombatantCondition;
use App\Models\ConditionDefinition;
use App\Models\CreatureInstance;
use App\Models\CreatureTemplate;
use App\Models\Encounter;
use App\Models\Item;
use App\Models\SceneToken;
use App\Models\Skill;
use App\Models\Spell;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CombatService
{
    private ?string $defeatedName = null;

    public function rollInitiative(Encounter $encounter): void
    {
        DB::transaction(function () use ($encounter) {
            $ranked = [];
            foreach ($encounter->combatants()->with('character', 'creature.template')->get() as $combatant) {
                $ranked[] = $this->rollCombatantInitiative($encounter, $combatant);
            }

            usort($ranked, function (Combatant $a, Combatant $b) {
                return [$b->initiative_total, $this->initiativeBonus($b), $b->initiative_tiebreak]
                    <=> [$a->initiative_total, $this->initiativeBonus($a), $a->initiative_tiebreak];
            });

            $first = $ranked[0] ?? null;
            $encounter->update([
                'status' => 'active',
                'round_number' => 1,
                'active_combatant_id' => $first?->id,
            ]);
            if ($first) {
                $encounter->refresh();
                $this->openTurn($encounter, $first);
                $this->logTurn($encounter, $first);
            }
        });

        event(new TableUpdated($encounter->campaign_id));
    }

    public function rollCombatantInitiative(Encounter $encounter, Combatant $combatant): Combatant
    {
        $combatant->loadMissing('character', 'creature.template');
        $bonus = $this->initiativeBonus($combatant);
        $dice = [random_int(1, 6), random_int(1, 6)];
        $roll = $dice[0] + $dice[1];
        $total = $roll + $bonus;
        $combatant->update([
            'initiative_roll' => $roll,
            'initiative_total' => $total,
            'initiative_tiebreak' => random_int(1, 6),
        ]);
        $combatant = $combatant->fresh(['character', 'creature.template']);
        $name = $this->name($combatant);
        $modifier = ($bonus < 0 ? '−' : '+').abs($bonus);
        $this->log($encounter, $combatant, null, 'initiative', [
            'summary' => "{$name} rolls initiative. Rolled {$dice[0]} and {$dice[1]}. Modifier {$modifier}. Initiative {$total}.",
            'attacker' => $name,
            'roll' => $roll,
            'dice' => $dice,
            'bonus' => $bonus,
            'total' => $total,
        ]);

        return $combatant;
    }

    public function spend(Combatant $combatant, string $kind, string $note): CombatEvent
    {
        $encounter = $combatant->encounter ?? Encounter::find($combatant->encounter_id);
        $turn = $this->currentTurn($encounter, $combatant);
        if ($kind === 'free') {
            if ($turn->free_remaining < 1) {
                throw ValidationException::withMessages(['action' => 'No free actions remain.']);
            }
            $turn->update(['free_remaining' => $turn->free_remaining - 1]);
        } elseif (in_array($kind, ['major', 'minor'], true)) {
            $pool = ActionPool::takeOne($combatant, $kind);
            $turn->update([
                'major_remaining' => $pool['major'],
                'minor_remaining' => $pool['minor'],
            ]);
        } else {
            throw ValidationException::withMessages(['action' => 'Use a major, minor, or free action.']);
        }
        $event = $this->log($encounter, $combatant, null, 'action', [
            'kind' => $kind,
            'note' => $note,
            'major' => $turn->major_remaining,
            'minor' => $turn->minor_remaining,
            'free' => $turn->free_remaining,
        ]);
        event(new TableUpdated($encounter->campaign_id));

        return $event;
    }

    public function skillCheck(Character $character, Skill $skill, int $circumstance, ?Combatant $actor = null): array
    {
        $sheet = $this->skillTier($character, $skill, $circumstance);
        $result = $this->resolveCheck($sheet['tier'], (int) $this->resource($character, 'crit_range'), $skill->is_attack_skill);
        $result['skill'] = $skill->name;
        $result['tier'] = $sheet['tier'];
        $result['circumstance'] = $circumstance;
        $result['modifier'] = AttackLog::circumstance($circumstance);
        $result['attacker'] = $character->name;
        $result['attacker_suffering'] = $this->sufferingRanks($character);
        $result['character_id'] = $character->id;
        $result['can_crit'] = (bool) $skill->is_attack_skill;
        $result['crit_range'] = $this->critRange($character);
        $result['summary'] = $this->checkSentence($result);

        $encounter = $actor?->encounter_id
            ? Encounter::find($actor->encounter_id)
            : ($character->campaign_id ? Encounter::query()->where('campaign_id', $character->campaign_id)->latest('id')->first() : null);
        if ($encounter) {
            $actor ??= Combatant::query()->where('encounter_id', $encounter->id)->where('character_id', $character->id)->first();
            $this->log($encounter, $actor, null, 'check', $result);
            event(new TableUpdated($encounter->campaign_id));
        }

        return $result;
    }

    public function attributeCheck(Character $character, string $key, int $circumstance = 0, ?Combatant $actor = null): array
    {
        $character->loadMissing('attributes.attribute', 'conditions.condition');
        $row = $character->attributes->first(function ($item) use ($key) {
            return $item->attribute?->key === $key || strcasecmp((string) $item->attribute?->name, $key) === 0;
        });
        if (! $row?->attribute) {
            throw ValidationException::withMessages(['attribute' => 'That attribute is not on this sheet.']);
        }
        $suffering = $this->sufferingRanks($character);
        $broken = $character->conditions->contains(fn ($item) => $item->condition?->key === 'broken');
        $tier = Percentile::tier((int) $row->base_step, 0, $circumstance, $suffering, $broken);
        $result = $this->resolveCheck($tier, (int) $this->resource($character, 'crit_range'), false);
        $result['skill'] = $row->attribute->name;
        $result['attribute'] = $row->attribute->name;
        $result['circumstance'] = $circumstance;
        $result['modifier'] = AttackLog::circumstance($circumstance);
        $result['attacker'] = $character->name;
        $result['tier'] = $tier;
        $result['attacker_suffering'] = $suffering;
        $result['can_crit'] = false;
        $result['crit_range'] = $this->critRange($character);
        $result['summary'] = $this->checkSentence($result);

        $encounter = $actor?->encounter_id
            ? Encounter::find($actor->encounter_id)
            : ($character->campaign_id ? Encounter::query()->where('campaign_id', $character->campaign_id)->latest('id')->first() : null);
        if ($encounter) {
            $actor ??= Combatant::query()->where('encounter_id', $encounter->id)->where('character_id', $character->id)->first();
            $this->log($encounter, $actor, null, 'check', $result);
            event(new TableUpdated($encounter->campaign_id));
        }

        return $result;
    }

    public function sheetAttack(Character $character, string $kind, ?int $itemId, ?int $spellId, bool $heavy, ?Combatant $actor = null, ?Combatant $target = null, ?Character $victimCharacter = null, ?CreatureInstance $victimCreature = null, int $circumstance = 0): array
    {
        $character->loadMissing('skills.skill');
        $item = $kind === 'weapon' ? Item::with('weapon.skill')->find($itemId) : null;
        $spell = $kind === 'spell' ? Spell::with('order.castingSkill')->find($spellId) : null;
        $skill = match ($kind) {
            'weapon' => $item?->weapon?->skill,
            'spell' => $spell?->order?->castingSkill ?? Skill::query()->where('key', 'sorcery')->first(),
            default => Skill::query()->where('key', 'unarmed')->first(),
        };
        if (! $skill) {
            throw ValidationException::withMessages(['attack' => 'That attack has no skill to roll.']);
        }

        $flags = $character->derivation_json['flags'] ?? [];
        $lesser = $kind === 'unarmed' && ! in_array('natural_weapons', $flags, true) && ! in_array('horns', $flags, true);
        $twoHandedMelee = ($item?->weapon?->hands_required ?? 1) >= 2 && $skill->key === 'melee';
        $great = (bool) ($item?->weapon?->properties_json['great'] ?? false);
        if ($heavy && ! $great) {
            $heavy = false;
        }
        $priced = ActionPool::spendCharacter($character, $heavy);
        $shift = ActionPool::noteCharacterAttack($character);
        $this->defeatedName = null;
        $forcedSave = $kind === 'spell' ? SpellAttack::forcedSave((string) $spell?->rules_text) : null;
        if ($forcedSave) {
            return $this->logSheetAttack($character, $this->spellSavePayload($character, $spell, $forcedSave, $priced, $victimCharacter, $victimCreature, $target, self::stackedCircumstance($circumstance, -$shift)), $actor, $target, $victimCharacter, $victimCreature);
        }

        $attackerSuffering = $this->sufferingRanks($character);
        $critRange = $this->critRange($character);
        $circumstance = self::stackedCircumstance($circumstance, $shift);
        $check = $this->resolveCheck($this->skillTier($character, $skill, $circumstance)['tier'], $critRange, true);
        $check['circumstance'] = $circumstance;
        $check['modifier'] = AttackLog::circumstance($circumstance);
        $check['skill'] = $skill->name;
        $check['attacker_suffering'] = $attackerSuffering;
        $check['attack'] = $item?->name ?? $spell?->name ?? 'Unarmed';
        $damageBase = $this->baseWounds($kind, $item, $spell);
        $defender = $victimCharacter ?? $target?->character;
        $targetArmor = $this->victimArmor($target, $victimCharacter, $victimCreature);
        $piercing = $this->trainedArmorPiercing($character, $item);
        $runeArmor = (bool) $defender?->preventsArmorPiercing();
        $damage = 0;
        $lesserRoll = null;
        $armor = [];
        if ($check['success']) {
            $lesserRoll = $lesser ? random_int(1, 100) : null;
            $armor = $this->applyArmor(
                AttackDamage::wounds($damageBase, $heavy, (bool) $check['critical'], $twoHandedMelee, $lesser, $lesserRoll, false),
                $targetArmor,
                $piercing,
                $runeArmor,
            );
            $damage = $armor['damage'];
            $check['target_wounds'] = $this->inflict($damage, $target, $victimCharacter, $victimCreature);
        }

        $payload = array_merge($check, $armor, [
            'damage' => $damage,
            'damage_base' => $damageBase,
            'crit_range' => $critRange,
            'can_crit' => true,
            'two_handed' => $twoHandedMelee,
            'lesser' => $lesser,
            'target_armor' => $targetArmor,
            'armor_piercing' => $piercing,
            'rune_armor_blocks' => $runeArmor,
            'lesser_roll' => $lesserRoll,
            'heavy' => $heavy,
            'actions' => $priced['actions'],
            'attacker' => $character->name,
            'target' => $victimCharacter?->name ?? $victimCreature?->name ?? $target?->character?->name ?? $target?->creature?->name,
            'target_type' => $victimCharacter ? 'character' : ($victimCreature ? 'creature' : ($target?->character_id ? 'character' : ($target?->creature_instance_id ? 'creature' : null))),
            'target_ref' => $victimCharacter?->id ?? $victimCreature?->id ?? $target?->character_id ?? $target?->creature_instance_id,
            'defeated' => $this->defeatedName,
        ]);
        if ($check['success'] && $victimCharacter && ($damage >= 1 || ! empty($armor['armor_saved']))) {
            $payload = array_merge($payload, $this->endureWounds($victimCharacter));
        }
        if ($check['success'] && $victimCreature && ($damage >= 1 || ! empty($armor['armor_saved']))) {
            $payload = array_merge($payload, $this->endureCreature($victimCreature));
        }
        $payload['summary'] = AttackLog::sentence($payload);

        return $this->logSheetAttack($character, $payload, $actor, $target, $victimCharacter, $victimCreature);
    }

    /**
     * @param  array{actions: list<string>, major: int, minor: int, extra_used: int}  $priced
     * @return array<string, mixed>
     */
    private function spellSavePayload(Character $character, Spell $spell, string $saveName, array $priced, ?Character $victimCharacter, ?CreatureInstance $victimCreature, ?Combatant $target, int $circumstance): array
    {
        $defender = $victimCharacter ?? $target?->character;
        $creature = $victimCreature ?? $target?->creature;
        if (! $defender && ! $creature) {
            throw ValidationException::withMessages(['attack' => 'Choose a target for that spell.']);
        }
        $save = $defender
            ? $this->resolveCheck($this->characterSaveTier($defender, $saveName, $circumstance), 3, false)
            : $this->resolveCheck($this->creatureSaveTier($creature, $saveName, $circumstance), 3, false);
        $landed = ($save['success'] ?? null) !== true;
        $rules = (string) $spell->rules_text;
        $damage = 0;
        $armor = [];
        if ($landed) {
            $base = SpellAttack::damage($rules);
            if ($base > 0) {
                $armor = $this->applyArmor($base, $this->victimArmor($target, $victimCharacter, $victimCreature), false, (bool) $defender?->preventsArmorPiercing());
                $damage = $armor['damage'];
                $this->inflict($damage, $target, $victimCharacter, $victimCreature);
            }
        }
        $payload = array_merge($armor, [
            'save' => true,
            'save_skill' => $saveName,
            'save_check' => $save,
            'success' => $landed,
            'critical' => false,
            'can_crit' => false,
            'damage' => $landed ? $damage : 0,
            'damage_base' => SpellAttack::damage($rules),
            'attack' => $spell->name,
            'skill' => $saveName,
            'attacker' => $character->name,
            'attacker_suffering' => $this->sufferingRanks($character),
            'circumstance' => $circumstance,
            'modifier' => AttackLog::circumstance($circumstance),
            'actions' => $priced['actions'],
            'target' => $defender?->name ?? $creature?->name,
            'target_type' => $defender ? 'character' : 'creature',
            'target_ref' => $defender?->id ?? $creature?->id,
            'defeated' => $this->defeatedName,
        ]);
        if ($landed && $defender && ($damage >= 1 || ! empty($armor['armor_saved']))) {
            $payload = array_merge($payload, $this->endureWounds($defender));
        }
        if ($landed && $creature && ($damage >= 1 || ! empty($armor['armor_saved']))) {
            $payload = array_merge($payload, $this->endureCreature($creature));
        }

        return $payload;
    }

    private function characterSaveTier(Character $character, string $saveName, int $circumstance): int
    {
        $skill = Skill::query()->whereRaw('lower(name) = ?', [strtolower($saveName)])->first();

        return $skill ? $this->skillTier($character, $skill, $circumstance)['tier'] : -1;
    }

    private function creatureSaveTier(CreatureInstance $creature, string $saveName, int $circumstance): int
    {
        $creature->loadMissing('template');
        $stats = $creature->template?->stats_json ?? [];
        $key = strtolower($saveName);
        $base = $stats[$key] ?? $stats['other'] ?? null;
        if (! is_numeric($base)) {
            return -1;
        }
        $suffering = max(0, min(2, (int) ($creature->overrides_json['suffering'] ?? 0)));

        return (int) $base - $suffering + $circumstance;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function logSheetAttack(Character $character, array $payload, ?Combatant $actor, ?Combatant $target, ?Character $victimCharacter, ?CreatureInstance $victimCreature): array
    {
        $payload['summary'] = AttackLog::sentence($payload);
        $encounter = $actor?->encounter_id
            ? Encounter::find($actor->encounter_id)
            : ($character->campaign_id ? Encounter::query()->where('campaign_id', $character->campaign_id)->latest()->first() : null);
        if ($encounter) {
            $actor ??= Combatant::query()->where('encounter_id', $encounter->id)->where('character_id', $character->id)->first();
            if (! $target && $victimCharacter) {
                $target = Combatant::query()->where('encounter_id', $encounter->id)->where('character_id', $victimCharacter->id)->first();
            }
            if (! $target && $victimCreature) {
                $target = Combatant::query()->where('encounter_id', $encounter->id)->where('creature_instance_id', $victimCreature->id)->first();
            }
            $this->log($encounter, $actor, $target, 'attack', $payload);
            event(new TableUpdated($encounter->campaign_id));
        }

        return $payload;
    }

    public function creatureStrike(CreatureTemplate $template, string $attackName, int $armorPercent = 0, int $suffering = 0, int $circumstance = 0, bool $runeArmor = false): array
    {
        $template->loadMissing('actions');
        $action = $template->actions->firstWhere('name', $attackName);
        if (! $action) {
            throw ValidationException::withMessages(['attack' => 'That creature does not have that attack.']);
        }
        if (($action->resolution_type ?? 'attack') === 'save') {
            return [
                'success' => null,
                'display' => '—',
                'critical' => false,
                'damage' => null,
                'percent' => null,
                'save' => true,
                'attack' => $action->name,
                'skill' => $template->name,
            ];
        }

        $tier = max(0, (int) ($action->attack_tier ?? $template->stats_json['attack_tier'] ?? 6) - max(0, min(2, $suffering))) + $circumstance;
        $percent = Percentile::successPercent($tier);
        $outcome = Percentile::outcome($tier);
        $success = false;
        $critical = false;
        $display = '—';
        $roll = null;
        if ($outcome === 'automatic') {
            $success = true;
            $display = 'Auto';
        } elseif ($outcome !== 'impossible') {
            $roll = random_int(1, 100);
            $success = $roll <= $percent;
            $critical = Percentile::isCritical($roll, 3, $success);
            $display = Percentile::display($roll);
        }
        $base = 1;
        if (preg_match('/(\d+)/', (string) ($action->effects_json['damage'] ?? '1'), $match)) {
            $base = (int) $match[1];
        }
        $heavy = (bool) ($action->effects_json['heavy'] ?? false);
        $twoHanded = (bool) ($action->effects_json['two_handed'] ?? false);
        $piercing = str_contains(strtolower((string) ($action->effects_json['tip'] ?? '')), 'armor piercing');
        $damage = null;
        $armor = [];
        if ($success) {
            $armor = $this->applyArmor(
                AttackDamage::wounds($base, $heavy, $critical, $twoHanded, false, null, false),
                $armorPercent,
                $piercing,
                $runeArmor,
            );
            $damage = $armor['damage'];
        }

        return array_merge($armor, [
            'success' => $success,
            'display' => $display,
            'critical' => $critical,
            'damage' => $damage,
            'damage_base' => $base,
            'heavy' => $heavy,
            'two_handed' => $twoHanded,
            'lesser' => false,
            'crit_range' => 3,
            'can_crit' => true,
            'target_armor' => $armorPercent,
            'armor_piercing' => $piercing,
            'rune_armor_blocks' => $runeArmor,
            'percent' => $percent,
            'save' => false,
            'attack' => $action->name,
            'skill' => $template->name,
            'roll' => $roll,
            'circumstance' => $circumstance,
            'modifier' => AttackLog::circumstance($circumstance),
        ]);
    }

    private function victimArmor(?Combatant $target, ?Character $victimCharacter, ?CreatureInstance $victimCreature): int
    {
        if ($target) {
            return $this->armorSave($target);
        }
        if ($victimCharacter) {
            return $this->characterArmorPercent($victimCharacter);
        }
        if ($victimCreature) {
            $victimCreature->loadMissing('template');

            return (int) ($victimCreature->overrides_json['armor_save_percent'] ?? $victimCreature->template?->armor_save_percent ?? 0);
        }

        return 0;
    }

    private function inflict(int $damage, ?Combatant $target, ?Character $victimCharacter, ?CreatureInstance $victimCreature): ?int
    {
        if ($target) {
            $this->applyWounds($target, $damage);

            return $this->currentWounds($target);
        }
        if ($victimCharacter) {
            return $this->dropCharacterWounds($victimCharacter, $damage);
        }
        if ($victimCreature) {
            return $this->woundCreature($victimCreature, $damage)['wounds'];
        }

        return null;
    }

    private function characterArmorPercent(Character $character): int
    {
        return $character->armorSavePercent();
    }

    /**
     * @return array{damage: int, armor_save?: int, armor_roll?: int, armor_saved?: bool, armor_capped?: bool, rune_armor?: bool}
     */
    private function applyArmor(int $damage, int $save, bool $piercing, bool $runeArmor): array
    {
        if ($damage < 1 || $save < 1) {
            return ['damage' => $damage];
        }
        $capped = $piercing && ! $runeArmor && $save > 10;
        $percent = $capped ? 10 : $save;
        $roll = random_int(1, 100);
        $saved = $roll <= $percent;

        return [
            'damage' => $saved ? max(0, $damage - 2) : $damage,
            'armor_save' => $percent,
            'armor_roll' => $roll,
            'armor_saved' => $saved,
            'armor_capped' => $capped,
            'rune_armor' => $piercing && $runeArmor && $save > 10,
        ];
    }

    private function trainedArmorPiercing(Character $character, ?Item $item): bool
    {
        if (! $item?->weapon) {
            return false;
        }
        $character->loadMissing('techniques.technique');
        $typeIds = $this->techniqueWeaponTypeIds($item);

        return $character->techniques->contains(function ($row) use ($typeIds) {
            return strcasecmp((string) $row->technique?->name, 'Armor Piercing') === 0
                && in_array((int) $row->weapon_type_id, $typeIds, true);
        });
    }

    /**
     * @return list<int>
     */
    private function techniqueWeaponTypeIds(Item $item): array
    {
        $item->loadMissing('weapon');
        $ids = $item->weapon ? [(int) $item->weapon->id] : [];
        if (preg_match('/counts as (?:an |a )([^.]+)/i', (string) $item->description, $match)) {
            $parent = Item::query()->where('name', trim($match[1]))->with('weapon')->first();
            if ($parent?->weapon) {
                $ids[] = (int) $parent->weapon->id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function baseWounds(string $kind, ?Item $item, ?Spell $spell): int
    {
        if ($kind === 'weapon') {
            return (int) ($item?->weapon?->base_damage ?? 1);
        }
        $rules = (string) ($spell?->rules_text ?? '');
        if (preg_match('/(\d+)d6/i', $rules, $dice)) {
            $total = 0;
            for ($i = 0; $i < (int) $dice[1]; $i++) {
                $total += random_int(1, 6);
            }

            return max(1, $total);
        }
        if (preg_match('/deal (\d+)/i', $rules, $flat)) {
            return (int) $flat[1];
        }

        return 1;
    }

    public function attack(Combatant $actor, Combatant $target, ?int $itemId, bool $heavy): array
    {
        $encounter = Encounter::find($actor->encounter_id);
        $turn = $this->currentTurn($encounter, $actor);
        $this->defeatedName = null;
        $actor->loadMissing(['character', 'creature']);
        if ($actor->character) {
            $priced = ActionPool::spendCharacter($actor->character, $heavy);
        } elseif ($actor->creature) {
            $priced = ActionPool::spendCreature($actor->creature, $heavy);
        } else {
            throw ValidationException::withMessages(['action' => 'That combatant cannot attack.']);
        }
        $actions = $priced['actions'];

        $weapon = $itemId ? Item::with('weapon.skill')->find($itemId) : null;
        $skill = $weapon?->weapon?->skill;
        $character = $actor->character;
        $critRange = 3;
        if ($character && $skill) {
            $shift = ActionPool::noteCharacterAttack($character);
            $sheet = $this->skillTier($character, $skill, $shift);
            $critRange = $this->critRange($character);
            $check = $this->resolveCheck($sheet['tier'], $critRange, true);
            $check['circumstance'] = $shift;
            $check['modifier'] = AttackLog::circumstance($shift);
            $check['skill'] = $skill->name;
            $check['attacker_suffering'] = $this->sufferingRanks($character);
        } else {
            $actor->loadMissing('creature.template');
            $shift = $actor->creature ? ActionPool::noteCreatureAttack($actor->creature) : 0;
            $suffering = max(0, min(2, (int) ($actor->creature?->overrides_json['suffering'] ?? $actor->creature?->template?->stats_json['suffering'] ?? 0)));
            $tier = max(0, (int) ($actor->creature?->template?->stats_json['attack_tier'] ?? 6) - $suffering + $shift);
            $check = $this->resolveCheck($tier, 3, true);
            $check['circumstance'] = $shift;
            $check['modifier'] = AttackLog::circumstance($shift);
            $check['skill'] = 'Attack';
            $check['attacker_suffering'] = $suffering;
        }

        $twoHandedMelee = ($weapon?->weapon?->hands_required ?? 1) >= 2
            && ($skill?->key === 'melee' || $skill === null && $actor->creature_instance_id);
        $damageBase = (int) ($weapon?->weapon?->base_damage ?? 1);
        $targetArmor = $this->armorSave($target);
        $piercing = $actor->character ? $this->trainedArmorPiercing($actor->character, $weapon) : false;
        $runeArmor = (bool) $target->character?->preventsArmorPiercing();
        $damage = 0;
        $armor = [];
        if ($check['success']) {
            $armor = $this->applyArmor(
                AttackDamage::wounds($damageBase, $heavy, (bool) $check['critical'], $twoHandedMelee, false, null, false),
                $targetArmor,
                $piercing,
                $runeArmor,
            );
            $damage = $armor['damage'];
            $this->applyWounds($target, $damage);
        }

        $turn->update([
            'major_remaining' => $priced['major'],
            'minor_remaining' => $priced['minor'],
            'attack_count' => $turn->attack_count + 1,
        ]);

        $actor->loadMissing(['character', 'creature']);
        $target->loadMissing(['character', 'creature']);
        $payload = array_merge($check, $armor, [
            'damage' => $damage,
            'damage_base' => $damageBase,
            'crit_range' => $critRange,
            'can_crit' => true,
            'two_handed' => $twoHandedMelee,
            'lesser' => false,
            'target_armor' => $targetArmor,
            'armor_piercing' => $piercing,
            'rune_armor_blocks' => $runeArmor,
            'weapon' => $weapon?->name,
            'attack' => $weapon?->name ?? 'an attack',
            'heavy' => $heavy,
            'actions' => $actions,
            'target_wounds' => $this->currentWounds($target),
            'attacker' => $actor->character?->name ?? $actor->creature?->name ?? 'Someone',
            'target' => $target->character?->name ?? $target->creature?->name,
            'target_type' => $target->character_id ? 'character' : 'creature',
            'target_ref' => $target->character_id ?: $target->creature_instance_id,
            'defeated' => $this->defeatedName,
        ]);
        if ($check['success'] && $target->character && ($damage >= 1 || ! empty($armor['armor_saved']))) {
            $payload = array_merge($payload, $this->endureWounds($target->character));
        }
        if ($check['success'] && $target->creature && ($damage >= 1 || ! empty($armor['armor_saved']))) {
            $payload = array_merge($payload, $this->endureCreature($target->creature));
        }
        $payload['summary'] = AttackLog::sentence($payload);
        $this->log($encounter, $actor, $target, 'attack', $payload);
        event(new TableUpdated($encounter->campaign_id));

        return $payload;
    }

    /**
     * @return array{endurance: array<string, mixed>, suffering: int}
     */
    public function endureWounds(Character $character): array
    {
        $skill = Skill::query()->where('key', 'endurance')->first();
        $tier = $skill ? $this->skillTier($character, $skill, 0)['tier'] : -1;
        $check = $this->resolveCheck($tier, 3, false);
        $ranks = $this->sufferingRanks($character);
        $gained = ! $check['success'] && $ranks < 2;
        if ($gained) {
            $ranks++;
            $this->writeSuffering($character, $ranks);
        }

        return [
            'endurance' => $check,
            'suffering' => $ranks,
            'suffering_gained' => $gained ? 1 : 0,
        ];
    }

    /**
     * @return array{endurance: array<string, mixed>, suffering: int}
     */
    public function endureCreature(CreatureInstance $instance): array
    {
        $instance->loadMissing('template');
        $ranks = max(0, min(2, (int) ($instance->overrides_json['suffering'] ?? 0)));
        $tier = (int) ($instance->template?->stats_json['endurance'] ?? 0) - $ranks;
        $check = $this->resolveCheck($tier, 3, false);
        $gained = ! $check['success'] && $ranks < 2;
        if ($gained) {
            $ranks++;
            $instance->refresh();
            $overrides = $instance->overrides_json ?? [];
            $overrides['suffering'] = $ranks;
            $instance->update(['overrides_json' => $overrides]);
        }

        return [
            'endurance' => $check,
            'suffering' => $ranks,
            'suffering_gained' => $gained ? 1 : 0,
        ];
    }

    /**
     * @return array{wounds: int, defeated: ?string}
     */
    public function woundCreature(CreatureInstance $instance, int $amount): array
    {
        $before = (int) $instance->current_wounds;
        $after = max(0, $before - max(0, $amount));
        $defeated = null;
        if ($amount > 0) {
            $instance->update(['current_wounds' => $after]);
            if ($before > 0 && $after === 0) {
                $defeated = $instance->name;
                $this->defeatedName = $instance->name;
                ActionPool::defeatCreature($instance);
                $instance->refresh();
            }
        }

        return ['wounds' => $amount > 0 ? $after : $before, 'defeated' => $defeated];
    }

    public function applyDamage(Combatant $target, int $amount, ?Combatant $actor, string $note): void
    {
        $this->defeatedName = null;
        $this->applyWounds($target, $amount);
        $encounter = Encounter::find($target->encounter_id);
        $this->log($encounter, $actor, $target, 'damage', [
            'amount' => $amount,
            'note' => $note,
            'wounds' => $this->currentWounds($target),
        ]);
        if ($this->defeatedName) {
            $character = $target->character ?? Character::find($target->character_id);
            if ($character) {
                $this->logDefeat($character, $encounter);
            }
        }
        event(new TableUpdated($encounter->campaign_id));
    }

    public function syncDefeat(Character $character, int $before, int $after): void
    {
        if ($before > 0 && $after <= 0) {
            ActionPool::defeat($character);
            $this->logDefeat($character);

            return;
        }
        if ($before <= 0 && $after > 0) {
            ActionPool::comeTo($character);
        }
    }

    public function applyCondition(Combatant $target, ConditionDefinition $condition, int $severity): void
    {
        $severity = max(1, min(2, $severity));
        $existing = CombatantCondition::query()
            ->where('condition_id', $condition->id)
            ->where(function ($query) use ($target) {
                if ($target->character_id) {
                    $query->where('character_id', $target->character_id);
                } else {
                    $query->where('creature_instance_id', $target->creature_instance_id);
                }
            })
            ->first();

        if ($existing && $condition->key === 'suffering') {
            $existing->update(['severity' => min(2, $existing->severity + $severity)]);
        } elseif (! $existing) {
            CombatantCondition::create([
                'combatant_id' => $target->id,
                'character_id' => $target->character_id,
                'creature_instance_id' => $target->creature_instance_id,
                'condition_id' => $condition->id,
                'severity' => $severity,
                'applied_round' => Encounter::find($target->encounter_id)?->round_number,
            ]);
        }

        $encounter = Encounter::find($target->encounter_id);
        $this->log($encounter, null, $target, 'condition', [
            'name' => $condition->name,
            'severity' => $severity,
        ]);
        event(new TableUpdated($encounter->campaign_id));
    }

    public function withdraw(Encounter $encounter, Combatant $combatant): void
    {
        DB::transaction(function () use ($encounter, $combatant) {
            $wasActive = (int) $encounter->active_combatant_id === (int) $combatant->id;
            $ordered = collect($this->ordered($encounter))->values();
            $index = $ordered->search(fn (Combatant $row) => (int) $row->id === (int) $combatant->id);
            $remaining = $ordered->reject(fn (Combatant $row) => (int) $row->id === (int) $combatant->id)->values();
            $next = null;
            if ($wasActive && $encounter->status === 'active') {
                CombatTurn::query()
                    ->where('encounter_id', $encounter->id)
                    ->where('combatant_id', $combatant->id)
                    ->where('round_number', $encounter->round_number)
                    ->whereNull('ended_at')
                    ->update(['ended_at' => now()]);
                if ($remaining->isEmpty()) {
                    $encounter->update(['active_combatant_id' => null]);
                } else {
                    $round = (int) $encounter->round_number;
                    if ($index === false || $index >= $remaining->count()) {
                        $next = $remaining->first();
                        $round++;
                    } else {
                        $next = $remaining[$index];
                    }
                    $encounter->update([
                        'round_number' => $round,
                        'active_combatant_id' => $next->id,
                    ]);
                }
            } elseif ($wasActive) {
                $encounter->update(['active_combatant_id' => null]);
            }

            SceneToken::query()->where('combatant_id', $combatant->id)->delete();
            $combatant->delete();

            if ($wasActive && $encounter->status === 'active') {
                $encounter->refresh();
                if ($next) {
                    $this->openTurn($encounter, $next);
                }
                $this->logTurn($encounter, $next);
            }
        });

        event(new TableUpdated($encounter->campaign_id));
    }

    public function endTurn(Encounter $encounter): void
    {
        $ordered = $this->ordered($encounter);
        $currentIndex = collect($ordered)->search(fn (Combatant $combatant) => $combatant->id === $encounter->active_combatant_id);
        $turn = CombatTurn::query()
            ->where('encounter_id', $encounter->id)
            ->where('combatant_id', $encounter->active_combatant_id)
            ->where('round_number', $encounter->round_number)
            ->first();
        $turn?->update(['ended_at' => now()]);

        $nextIndex = ($currentIndex === false ? 0 : $currentIndex + 1);
        $round = $encounter->round_number;
        if ($nextIndex >= count($ordered)) {
            $nextIndex = 0;
            $round++;
        }
        $next = $ordered[$nextIndex] ?? null;
        $encounter->update([
            'round_number' => $round,
            'active_combatant_id' => $next?->id,
        ]);
        if ($next) {
            $this->openTurn($encounter, $next);
        }
        $this->logTurn($encounter, $next);
        event(new TableUpdated($encounter->campaign_id));
    }

    public function reverseLuck(Character $character, CombatEvent $event): array
    {
        $payload = $event->payload_json ?? [];
        $roll = $payload['roll'] ?? null;
        if (! is_int($roll) && ! is_numeric($roll)) {
            throw ValidationException::withMessages(['luck' => 'That result cannot be reversed.']);
        }
        if (! is_numeric($payload['percent'] ?? null)) {
            throw ValidationException::withMessages(['luck' => 'Luck cannot affect a check that required no roll.']);
        }
        if (! empty($payload['reversed'])) {
            throw ValidationException::withMessages(['luck' => 'A roll can be reversed only once.']);
        }
        $encounter = Encounter::find($event->encounter_id);
        if (! $encounter || ! Combatant::query()->where('encounter_id', $encounter->id)->where('character_id', $character->id)->exists()) {
            throw ValidationException::withMessages(['luck' => 'That character is not in this combat.']);
        }
        $luck = CharacterResource::query()->where('character_id', $character->id)->where('resource_key', 'luck')->first();
        if (! $luck || $luck->current_value < 1) {
            throw ValidationException::withMessages(['luck' => 'No Luck remaining.']);
        }
        $reversed = Percentile::reverseLuck((int) $roll);
        if ($reversed === null) {
            throw ValidationException::withMessages(['luck' => 'That roll cannot be reversed.']);
        }

        $oldSuccess = ($payload['success'] ?? null) === true;
        $oldDamage = (int) ($payload['damage'] ?? 0);
        $oldArmorSaved = ! empty($payload['armor_saved']);
        $percent = (int) $payload['percent'];
        $success = $reversed <= $percent;
        $canCrit = (bool) ($payload['can_crit'] ?? $event->event_type === 'attack');
        $critRange = (int) ($payload['crit_range'] ?? 3);
        $critical = $canCrit && Percentile::isCritical($reversed, $critRange, $success);
        $payload['original_roll'] = (int) $roll;
        $payload['roll'] = $reversed;
        $payload['display'] = Percentile::display($reversed);
        $payload['success'] = $success;
        $payload['critical'] = $critical;
        $payload['reversed'] = true;

        if ($event->event_type === 'attack' && empty($payload['save'])) {
            $payload = $this->recalculateAttack($event, $payload, $oldSuccess, $oldDamage, $oldArmorSaved, $success, $critical);
            $payload['summary'] = AttackLog::sentence($payload);
        } else {
            $payload['summary'] = $this->checkSentence($payload);
        }

        $event->update(['payload_json' => $payload]);
        $luck->update(['current_value' => $luck->current_value - 1]);
        event(new TableUpdated($encounter->campaign_id));

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function recalculateAttack(CombatEvent $event, array $payload, bool $oldSuccess, int $oldDamage, bool $oldArmorSaved, bool $success, bool $critical): array
    {
        $this->defeatedName = null;
        $base = (int) ($payload['damage_base'] ?? 1);
        $lesser = (bool) ($payload['lesser'] ?? false);
        $lesserRoll = is_numeric($payload['lesser_roll'] ?? null) ? (int) $payload['lesser_roll'] : null;
        if ($success && $lesser && $lesserRoll === null) {
            $lesserRoll = random_int(1, 100);
            $payload['lesser_roll'] = $lesserRoll;
        }
        $raw = AttackDamage::wounds($base, (bool) ($payload['heavy'] ?? false), $critical, (bool) ($payload['two_handed'] ?? false), $lesser, $lesserRoll, false);
        if (! $success) {
            $payload['damage'] = 0;
            $newArmorSaved = false;
        } elseif (is_numeric($payload['armor_roll'] ?? null) && is_numeric($payload['armor_save'] ?? null)) {
            $newArmorSaved = (int) $payload['armor_roll'] <= (int) $payload['armor_save'];
            $payload['armor_saved'] = $newArmorSaved;
            $payload['damage'] = $newArmorSaved ? max(0, $raw - 2) : $raw;
        } else {
            $armor = $this->applyArmor(
                $raw,
                (int) ($payload['target_armor'] ?? 0),
                (bool) ($payload['armor_piercing'] ?? false),
                (bool) ($payload['rune_armor_blocks'] ?? false),
            );
            $payload = array_merge($payload, $armor);
            $newArmorSaved = ! empty($payload['armor_saved']);
        }
        $newDamage = (int) $payload['damage'];
        $wounds = $this->adjustEventWounds($event, $newDamage - $oldDamage);
        if ($wounds !== null) {
            $payload['target_wounds'] = $wounds;
        }
        if ($this->defeatedName) {
            $payload['defeated'] = $this->defeatedName;
        } elseif ($wounds !== null && $wounds > 0) {
            unset($payload['defeated']);
        }

        $oldNeeded = $oldSuccess && ($oldDamage >= 1 || $oldArmorSaved);
        $newNeeded = $success && ($newDamage >= 1 || $newArmorSaved);
        if ($oldNeeded && ! $newNeeded) {
            $this->undoSuffering($event, $payload);
            unset($payload['endurance'], $payload['suffering_gained']);
        } elseif (! $oldNeeded && $newNeeded) {
            $payload = $this->applyEnduranceForEvent($event, $payload);
        }

        return $payload;
    }

    private function adjustEventWounds(CombatEvent $event, int $delta): ?int
    {
        if ($delta === 0 || ! $event->target_id) {
            return $event->target_id ? $this->currentWounds(Combatant::find($event->target_id)) : null;
        }
        $target = Combatant::with(['character', 'creature.template'])->find($event->target_id);
        if (! $target) {
            return null;
        }
        if ($delta > 0) {
            $this->applyWounds($target, $delta);

            return $this->currentWounds($target);
        }
        $amount = -$delta;
        if ($target->character) {
            $resource = CharacterResource::query()->where('character_id', $target->character_id)->where('resource_key', 'wounds')->first();
            if (! $resource) {
                return null;
            }
            $before = (int) $resource->current_value;
            $after = min((int) $resource->base_maximum, $before + $amount);
            $resource->update(['current_value' => $after]);
            if ($before <= 0 && $after > 0) {
                ActionPool::comeTo($target->character);
            }

            return $after;
        }
        if ($target->creature) {
            $before = (int) $target->creature->current_wounds;
            $max = (int) ($target->creature->template?->base_wounds ?? $before);
            $after = min(max($max, $before), $before + $amount);
            $target->creature->update(['current_wounds' => $after]);
            if ($before <= 0 && $after > 0) {
                ActionPool::refreshCreature($target->creature);
            }

            return $after;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function undoSuffering(CombatEvent $event, array $payload): void
    {
        $gained = (int) ($payload['suffering_gained'] ?? ((($payload['endurance']['success'] ?? null) === false) ? 1 : 0));
        if ($gained < 1 || ! $event->target_id) {
            return;
        }
        $target = Combatant::with(['character', 'creature'])->find($event->target_id);
        if ($target?->character) {
            $this->writeSuffering($target->character, $this->sufferingRanks($target->character) - $gained);
        } elseif ($target?->creature) {
            $overrides = $target->creature->overrides_json ?? [];
            $overrides['suffering'] = max(0, (int) ($overrides['suffering'] ?? 0) - $gained);
            $target->creature->update(['overrides_json' => $overrides]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyEnduranceForEvent(CombatEvent $event, array $payload): array
    {
        if (! $event->target_id) {
            return $payload;
        }
        $target = Combatant::with(['character', 'creature'])->find($event->target_id);
        if ($target?->character) {
            return array_merge($payload, $this->endureWounds($target->character));
        }
        if ($target?->creature) {
            return array_merge($payload, $this->endureCreature($target->creature));
        }

        return $payload;
    }

    private function openTurn(Encounter $encounter, Combatant $combatant): CombatTurn
    {
        $turn = CombatTurn::query()->firstOrCreate([
            'encounter_id' => $encounter->id,
            'combatant_id' => $combatant->id,
            'round_number' => $encounter->round_number,
        ], [
            'major_remaining' => ActionPool::MAJOR_MAX,
            'minor_remaining' => ActionPool::MINOR_MAX,
            'free_remaining' => 1,
            'started_at' => now(),
        ]);
        if ($turn->wasRecentlyCreated) {
            $combatant->loadMissing(['character', 'creature']);
            if ($combatant->character) {
                ActionPool::refreshCharacter($combatant->character);
            }
            if ($combatant->creature) {
                ActionPool::refreshCreature($combatant->creature);
            }
        }

        return $turn;
    }

    private function currentTurn(Encounter $encounter, Combatant $combatant): CombatTurn
    {
        if ($encounter->active_combatant_id !== $combatant->id || $encounter->status !== 'active') {
            throw ValidationException::withMessages(['action' => 'It is not this combatant’s turn.']);
        }

        return $this->openTurn($encounter, $combatant);
    }

    private static function stackedCircumstance(int $chosen, int $shift): int
    {
        return max(-2, min(2, $chosen + $shift));
    }

    /**
     * @return array{tier: int}
     */
    private function skillTier(Character $character, Skill $skill, int $circumstance): array
    {
        $character->loadMissing('attributes.attribute', 'skills.skill', 'conditions.condition');
        $rank = (int) ($character->skills->firstWhere('skill_id', $skill->id)?->base_rank ?? 0);
        $step = (int) ($character->attributes->first(fn ($row) => $row->attribute_id === $skill->attribute_id)?->base_step ?? 6);
        $suffering = (int) $character->conditions->filter(fn ($row) => $row->condition?->key === 'suffering')->sum('severity');
        $broken = $character->conditions->contains(fn ($row) => $row->condition?->key === 'broken');

        return ['tier' => Percentile::tier($step, $rank, $circumstance, $suffering, $broken)];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveCheck(int $tier, int $critRange, bool $canCrit): array
    {
        $outcome = Percentile::outcome($tier);
        $percent = Percentile::successPercent($tier);
        if ($outcome === 'impossible') {
            return ['outcome' => $outcome, 'success' => false, 'critical' => false, 'roll' => null, 'display' => '—', 'percent' => null];
        }
        if ($outcome === 'automatic') {
            return ['outcome' => $outcome, 'success' => true, 'critical' => false, 'roll' => null, 'display' => 'Auto', 'percent' => 100];
        }
        $roll = random_int(1, 100);
        $success = $roll <= $percent;

        return [
            'outcome' => $outcome,
            'success' => $success,
            'critical' => $canCrit && Percentile::isCritical($roll, $critRange, $success),
            'roll' => $roll,
            'display' => Percentile::display($roll),
            'percent' => $percent,
        ];
    }

    private function armorSave(Combatant $target): int
    {
        if ($target->creature) {
            return (int) ($target->creature->overrides_json['armor_save_percent'] ?? $target->creature->template->armor_save_percent ?? 0);
        }
        $character = $target->character;
        if (! $character) {
            return 0;
        }

        return $character->armorSavePercent();
    }

    private function sufferingRanks(Character $character): int
    {
        $character->load('conditions.condition');

        return (int) $character->conditions->filter(fn ($row) => $row->condition?->key === 'suffering')->sum('severity');
    }

    private function writeSuffering(Character $character, int $ranks): void
    {
        $ranks = max(0, min(2, $ranks));
        $condition = ConditionDefinition::query()->where('key', 'suffering')->first();
        if (! $condition) {
            return;
        }
        $existing = CombatantCondition::query()
            ->where('character_id', $character->id)
            ->where('condition_id', $condition->id)
            ->first();
        if ($ranks === 0) {
            $existing?->delete();

            return;
        }
        if ($existing) {
            $existing->update(['severity' => $ranks]);

            return;
        }
        CombatantCondition::create([
            'character_id' => $character->id,
            'condition_id' => $condition->id,
            'severity' => $ranks,
        ]);
    }

    private function applyWounds(Combatant $target, int $amount): void
    {
        if ($amount === 0) {
            return;
        }
        if ($target->character_id) {
            $character = $target->character ?? Character::find($target->character_id);
            if ($character) {
                $this->dropCharacterWounds($character, $amount);
            }
        }
        if ($target->creature_instance_id) {
            $instance = $target->creature ?? CreatureInstance::find($target->creature_instance_id);
            if ($instance) {
                $this->woundCreature($instance, $amount);
            }
        }
    }

    private function dropCharacterWounds(Character $character, int $amount): ?int
    {
        $resource = CharacterResource::query()->where('character_id', $character->id)->where('resource_key', 'wounds')->first();
        if (! $resource) {
            return null;
        }
        $before = (int) $resource->current_value;
        $after = max(0, $before - $amount);
        $resource->update(['current_value' => $after]);
        if ($before > 0 && $after === 0) {
            ActionPool::defeat($character);
            $this->defeatedName = $character->name;
        }

        return $after;
    }

    private function logDefeat(Character $character, ?Encounter $encounter = null): void
    {
        $encounter ??= $character->campaign_id
            ? Encounter::query()->where('campaign_id', $character->campaign_id)->latest('id')->first()
            : null;
        if (! $encounter) {
            return;
        }
        $combatant = Combatant::query()->where('encounter_id', $encounter->id)->where('character_id', $character->id)->first();
        $this->log($encounter, null, $combatant, 'defeat', [
            'summary' => $character->name.' has been defeated.',
            'target' => $character->name,
        ]);
        event(new TableUpdated($encounter->campaign_id));
    }

    private function currentWounds(Combatant $target): int
    {
        if ($target->character_id) {
            return (int) CharacterResource::query()->where('character_id', $target->character_id)->where('resource_key', 'wounds')->value('current_value');
        }

        return (int) CreatureInstance::find($target->creature_instance_id)?->current_wounds;
    }

    private function initiativeBonus(Combatant $combatant): int
    {
        if ($combatant->character_id) {
            return (int) $this->resource($combatant->character, 'initiative_bonus');
        }

        $stats = $combatant->creature?->template?->stats_json ?? [];

        return (int) ($stats['initiative_bonus'] ?? $stats['initiative'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function checkSentence(array $payload): string
    {
        $who = $payload['attacker'] ?? 'Someone';
        $name = $payload['attribute'] ?? $payload['skill'] ?? 'a check';
        $line = "{$who} checks {$name}.";
        $modifier = AttackLog::circumstance((int) ($payload['circumstance'] ?? 0));
        if ($modifier !== '') {
            $line .= ' '.$modifier.'.';
        }
        if (is_numeric($payload['percent'] ?? null)) {
            $line .= " ({$payload['percent']}% chance.";
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
        $line .= AttackLog::luckClause($payload);
        if (($payload['success'] ?? null) === true) {
            $line .= ' Success.';
        } elseif (($payload['success'] ?? null) === false) {
            $line .= ' Failure.';
        }

        return $line;
    }

    private function critRange(?Character $character): int
    {
        $range = $this->resource($character, 'crit_range');

        return $range > 0 ? $range : 3;
    }

    private function resource(?Character $character, string $key): int
    {
        if (! $character) {
            return 0;
        }

        return (int) CharacterResource::query()->where('character_id', $character->id)->where('resource_key', $key)->value('base_maximum');
    }

    /**
     * @return array<int, Combatant>
     */
    private function ordered(Encounter $encounter): array
    {
        return $encounter->combatants()->with('creature.template', 'character')->get()->sortBy([
            ['initiative_total', 'desc'],
            ['initiative_tiebreak', 'desc'],
        ])->values()->all();
    }

    private function logTurn(Encounter $encounter, ?Combatant $combatant): void
    {
        $who = $this->name($combatant);
        $round = $encounter->round_number;
        $this->log($encounter, $combatant, null, 'turn', [
            'round' => $round,
            'combatant' => $combatant ? $who : null,
            'summary' => $combatant
                ? "It is {$who}'s turn. Round {$round}."
                : 'No one remains to take a turn.',
        ]);
    }

    private function name(?Combatant $combatant): string
    {
        return $combatant?->character?->name ?? $combatant?->creature?->name ?? 'Someone';
    }

    /**
     * @return array<string, mixed>
     */
    public function manualDie(string $name, string $die): array
    {
        $faces = match ($die) {
            'percentile' => [random_int(1, 100)],
            '1d6' => [random_int(1, 6)],
            '2d6' => [random_int(1, 6), random_int(1, 6)],
            default => throw ValidationException::withMessages(['die' => 'Choose a percentile roll, 2d6, or 1d6.']),
        };
        $total = array_sum($faces);
        $display = $die === 'percentile' ? Percentile::display($total) : (string) $total;
        $summary = match ($die) {
            'percentile' => "{$name} rolls percentile. Rolled {$display}.",
            '1d6' => "{$name} rolls 1d6. Rolled {$total}.",
            '2d6' => "{$name} rolls 2d6. Rolled {$faces[0]} and {$faces[1]}, total {$total}.",
        };

        return [
            'die' => $die,
            'attacker' => $name,
            'roll' => $total,
            'display' => $display,
            'faces' => $faces,
            'summary' => $summary,
            'note' => $summary,
        ];
    }

    public function record(Encounter $encounter, ?Combatant $actor, ?Combatant $target, string $type, array $payload): CombatEvent
    {
        $event = $this->log($encounter, $actor, $target, $type, $payload);
        event(new TableUpdated($encounter->campaign_id));

        return $event;
    }

    private function log(Encounter $encounter, ?Combatant $actor, ?Combatant $target, string $type, array $payload): CombatEvent
    {
        return CombatEvent::create([
            'encounter_id' => $encounter->id,
            'actor_id' => $actor?->id,
            'target_id' => $target?->id,
            'event_type' => $type,
            'payload_json' => $payload,
        ]);
    }
}
