<?php

namespace App\Http\Controllers;

use App\Domain\Campaigns\CampaignAccess;
use App\Domain\Characters\CharacterAdvancement;
use App\Domain\Characters\CharacterCreator;
use App\Domain\Characters\CharacterPresenter;
use App\Domain\Combat\ActionPool;
use App\Domain\Combat\CombatService;
use App\Models\Background;
use App\Models\Campaign;
use App\Models\Character;
use App\Models\CharacterInventory;
use App\Models\CharacterSpell;
use App\Models\CharacterTechnique;
use App\Models\CharacterResource;
use App\Models\CharacterWallet;
use App\Models\CombatEvent;
use App\Models\CreatureInstance;
use App\Models\Combatant;
use App\Models\CombatantCondition;
use App\Models\ConditionDefinition;
use App\Models\Feature;
use App\Models\HeroClass;
use App\Models\Item;
use App\Models\Skill;
use App\Models\Specialization;
use App\Models\Species;
use App\Models\Spell;
use App\Models\WeaponType;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class CharacterController extends Controller
{
    public function index()
    {
        $characters = Character::query()
            ->where('user_id', request()->user()->id)
            ->with(['species', 'classLevels.heroClass'])
            ->latest()
            ->get()
            ->map(fn (Character $character) => [
                'id' => $character->id,
                'name' => $character->name,
                'letter' => strtoupper(substr($character->name, 0, 1)),
                'portrait_url' => $character->portraitUrl(),
                'species' => $character->species?->name,
                'classes' => $character->classLevels->map(fn ($row) => $row->heroClass?->name.' '.$row->class_level)->implode(' / '),
            ]);

        return Inertia::render('Characters/Index', ['characters' => $characters]);
    }

    public function create()
    {
        return Inertia::render('Characters/Wizard', [
            'species' => Species::query()->visibleTo(request()->user())->orderBy('name')->get(),
            'backgrounds' => Background::query()->visibleTo(request()->user())->orderBy('name')->get(),
            'classes' => HeroClass::query()->visibleTo(request()->user())->with(['role', 'primarySkills.attribute'])->orderBy('name')->get(),
            'specializations' => Specialization::query()->visibleTo(request()->user())->orderBy('name')->get(),
            'features' => Feature::query()->visibleTo(request()->user())->whereIn('category', ['creation_primary', 'creation_secondary', 'background', 'class', 'talent', 'tactic'])->orderBy('name')->get(),
            'skills' => Skill::query()->with('attribute')->orderBy('name')->get(),
            'items' => Item::query()->visibleTo(request()->user())->orderBy('category')->orderBy('name')->get(['id', 'name', 'category', 'cost_silver', 'description']),
            'spells' => Spell::query()->visibleTo(request()->user())->with('order')->orderBy('tier')->orderBy('name')->get(),
            'techniques' => $this->weaponTechniques(request()->user()),
        ]);
    }

    public function store(Request $request, CharacterCreator $creator)
    {
        $character = $creator->create($request->user(), $request->all());

        return redirect()->route('characters.show', $character)->with('success', $character->name.' steps out of the dark.');
    }

    public function destroy(Character $character, CombatService $combat)
    {
        abort_unless((int) $character->user_id === (int) request()->user()->id, 403);
        $campaignId = $character->campaign_id;
        $combatants = Combatant::query()->where('character_id', $character->id)->with('encounter')->get();
        foreach ($combatants as $combatant) {
            if ($combatant->encounter) {
                $combat->withdraw($combatant->encounter, $combatant);
            } else {
                \App\Models\SceneToken::query()->where('combatant_id', $combatant->id)->delete();
                $combatant->delete();
            }
        }
        $character->delete();
        if ($campaignId) {
            event(new \App\Events\TableUpdated($campaignId));
        }

        return redirect()->route('characters.index');
    }

    public function show(Character $character, CharacterPresenter $presenter)
    {
        $this->authorizeView($character);
        $encounter = null;
        if ($character->campaign_id) {
            $encounter = \App\Models\Encounter::query()
                ->where('campaign_id', $character->campaign_id)
                ->where('status', 'active')
                ->with('combatants.character', 'combatants.creature')
                ->latest()
                ->first();
        }

        return Inertia::render('Characters/Show', [
            'sheet' => $presenter->present($character),
            'classes' => HeroClass::query()->visibleTo(request()->user())->with('role')->orderBy('name')->get()->map(fn (HeroClass $class) => [
                'id' => $class->id,
                'name' => $class->name,
                'role' => $class->role?->name,
                'progression' => collect($class->progression_json ?? [])->map(fn ($level) => [
                    'level' => (int) ($level['level'] ?? 0),
                    'feature' => $level['feature'] ?? '',
                    'choice' => CharacterAdvancement::choiceKinds((string) ($level['feature'] ?? '')),
                    'spell' => CharacterAdvancement::spellChoice((string) ($level['feature'] ?? '')),
                ])->values(),
            ]),
            'items' => Item::query()
                ->visibleTo(request()->user())
                ->orderBy('name')
                ->get(['id', 'name', 'category']),
            'spells' => Spell::query()
                ->visibleTo(request()->user())
                ->with('order')
                ->orderBy('name')
                ->get()
                ->map(fn (Spell $spell) => [
                    'id' => $spell->id,
                    'name' => $spell->name,
                    'order' => $spell->order?->name,
                    'tier' => $spell->tier,
                ]),
            'conditions' => ConditionDefinition::query()->where('key', '!=', 'suffering')->orderBy('name')->get(['id', 'name', 'key', 'description']),
            'techniques' => $this->weaponTechniques(request()->user()),
            'attackSkills' => Skill::query()->where('is_attack_skill', true)->orderBy('name')->get(['id', 'key', 'name']),
            'talents' => Feature::query()->whereIn('category', ['talent', 'tactic'])->orderBy('name')->get(['id', 'name', 'category']),
            'targets' => $character->campaign_id ? $this->campaignTargets($character) : [],
            'encounter' => $encounter ? [
                'id' => $encounter->id,
                'round' => $encounter->round_number,
                'combatants' => $encounter->combatants->map(fn ($row) => [
                    'id' => $row->id,
                    'name' => $row->character?->name ?? $row->creature?->name,
                    'character_id' => $row->character_id,
                ]),
            ] : null,
        ]);
    }

    public function update(Request $request, Character $character)
    {
        $this->authorizeView($character);
        $data = $request->validate([
            'gender' => ['nullable', 'string', 'max:40'],
            'pronouns' => ['nullable', 'string', 'max:40'],
            'height' => ['nullable', 'string', 'max:40'],
            'weight' => ['nullable', 'string', 'max:40'],
            'age' => ['nullable', 'string', 'max:40'],
            'eyes' => ['nullable', 'string', 'max:40'],
            'hair' => ['nullable', 'string', 'max:40'],
            'biography' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'name' => ['nullable', 'string', 'max:80'],
            'xp' => ['nullable', 'integer', 'min:0'],
            'resources' => ['nullable', 'array'],
            'suffering' => ['nullable', 'integer', 'min:0', 'max:2'],
            'gold' => ['nullable', 'integer', 'min:0'],
            'silver' => ['nullable', 'integer', 'min:0'],
        ]);
        $woundsBefore = (int) CharacterResource::query()->where('character_id', $character->id)->where('resource_key', 'wounds')->value('current_value');
        $character->update(collect($data)->only(['gender', 'pronouns', 'height', 'weight', 'age', 'eyes', 'hair', 'biography', 'notes', 'name', 'xp'])->filter(fn ($value) => $value !== null)->all());
        foreach ($data['resources'] ?? [] as $key => $current) {
            if (! is_numeric($current)) {
                continue;
            }
            $max = match ($key) {
                'major_action' => 1,
                'minor_action' => 2,
                default => null,
            };
            if ($max !== null) {
                CharacterResource::query()->updateOrCreate(
                    ['character_id' => $character->id, 'resource_key' => $key],
                    ['base_maximum' => $max, 'current_value' => max(0, min($max, (int) $current))],
                );

                continue;
            }
            $resource = CharacterResource::query()->where('character_id', $character->id)->where('resource_key', $key)->first();
            if ($resource) {
                $resource->update(['current_value' => max(0, min($resource->base_maximum, (int) $current))]);
            }
        }
        if (isset($data['resources']['major_action']) && (int) $data['resources']['major_action'] >= 1) {
            CharacterResource::query()->updateOrCreate(
                ['character_id' => $character->id, 'resource_key' => 'extra_attacks_used'],
                ['base_maximum' => 2, 'current_value' => 0],
            );
        }
        ActionPool::syncCharacter($character);
        if (isset($data['resources']['wounds']) && is_numeric($data['resources']['wounds'])) {
            $woundsAfter = (int) CharacterResource::query()->where('character_id', $character->id)->where('resource_key', 'wounds')->value('current_value');
            app(CombatService::class)->syncDefeat($character, $woundsBefore, $woundsAfter);
        }
        if (array_key_exists('suffering', $data)) {
            $this->setSuffering($character, (int) $data['suffering']);
        }
        if (array_key_exists('gold', $data) || array_key_exists('silver', $data)) {
            $current = (int) ($character->wallet?->balance_silver ?? 0);
            $gold = array_key_exists('gold', $data) ? (int) $data['gold'] : intdiv($current, 10);
            $silver = array_key_exists('silver', $data) ? (int) $data['silver'] : $current % 10;
            CharacterWallet::query()->updateOrCreate(
                ['character_id' => $character->id],
                ['balance_silver' => $gold * 10 + $silver],
            );
        }

        return back()->with('success', 'Details saved.');
    }

    public function addCondition(Request $request, Character $character)
    {
        $this->authorizeView($character);
        $condition = $this->statusCondition($request);
        CombatantCondition::query()->firstOrCreate([
            'character_id' => $character->id,
            'condition_id' => $condition->id,
        ], ['severity' => 1]);
        $this->broadcastTable($character->campaign_id);

        return back();
    }

    public function removeCondition(Character $character, CombatantCondition $combatantCondition)
    {
        $this->authorizeView($character);
        abort_unless((int) $combatantCondition->character_id === (int) $character->id, 404);
        abort_if($combatantCondition->condition?->key === 'suffering', 404);
        $combatantCondition->delete();
        $this->broadcastTable($character->campaign_id);

        return back();
    }

    private function statusCondition(Request $request): ConditionDefinition
    {
        $condition = ConditionDefinition::query()->findOrFail($request->validate([
            'condition_id' => ['required', 'integer'],
        ])['condition_id']);
        abort_if($condition->key === 'suffering', 404);

        return $condition;
    }

    private function broadcastTable(?int $campaignId): void
    {
        if ($campaignId) {
            event(new \App\Events\TableUpdated($campaignId));
        }
    }

    private function setSuffering(Character $character, int $ranks): void
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

    public function addClass(Request $request, Character $character, CharacterAdvancement $advancement)
    {
        $this->authorizeView($character);
        $data = $request->validate([
            'class_id' => ['required', 'integer'],
            'choices' => ['nullable', 'array'],
        ]);
        $advancement->addLevel($character, (int) $data['class_id'], $data['choices'] ?? []);

        return back()->with('success', 'Level recorded.');
    }

    public function roll(Request $request, Character $character, CombatService $combat)
    {
        $this->authorizeView($character);
        $data = $request->validate([
            'skill_id' => ['required_without:attribute', 'nullable', 'integer'],
            'attribute' => ['required_without:skill_id', 'nullable', 'string'],
            'circumstance' => ['nullable', 'integer', 'between:-2,2'],
            'combatant_id' => ['nullable', 'integer'],
        ]);
        $actor = isset($data['combatant_id']) ? Combatant::find($data['combatant_id']) : null;
        $result = filled($data['attribute'] ?? null)
            ? $combat->attributeCheck($character, $data['attribute'], (int) ($data['circumstance'] ?? 0), $actor)
            : $combat->skillCheck($character, Skill::findOrFail($data['skill_id']), (int) ($data['circumstance'] ?? 0), $actor);
        $result['character_id'] = $character->id;

        return back()->with('roll', $result);
    }

    public function attack(Request $request, Character $character, CombatService $combat)
    {
        $this->authorizeView($character);
        $data = $request->validate([
            'kind' => ['required', 'in:weapon,unarmed,spell'],
            'item_id' => ['nullable', 'integer'],
            'spell_id' => ['nullable', 'integer'],
            'heavy' => ['nullable', 'boolean'],
            'combatant_id' => ['nullable', 'integer'],
            'target_type' => ['nullable', 'in:combatant,character,creature'],
            'target_id' => ['nullable', 'integer'],
            'circumstance' => ['nullable', 'integer', 'between:-2,2'],
        ]);
        $actor = isset($data['combatant_id']) ? Combatant::find($data['combatant_id']) : null;
        $target = ($data['target_type'] ?? null) === 'combatant' && isset($data['target_id']) ? Combatant::find($data['target_id']) : null;
        $victimCharacter = ($data['target_type'] ?? null) === 'character' && isset($data['target_id'])
            ? Character::query()->where('campaign_id', $character->campaign_id)->find($data['target_id'])
            : null;
        $victimCreature = ($data['target_type'] ?? null) === 'creature' && isset($data['target_id'])
            ? CreatureInstance::query()->where('campaign_id', $character->campaign_id)->find($data['target_id'])
            : null;
        $result = $combat->sheetAttack(
            $character,
            $data['kind'],
            $data['item_id'] ?? null,
            $data['spell_id'] ?? null,
            $request->boolean('heavy'),
            $actor,
            $target,
            $victimCharacter,
            $victimCreature,
            (int) ($data['circumstance'] ?? 0),
        );
        $result['character_id'] = $character->id;

        return back()->with('roll', $result);
    }

    public function addSpell(Request $request, Character $character)
    {
        $this->authorizeView($character);
        $spell = Spell::query()->visibleTo($request->user())->findOrFail($request->validate([
            'spell_id' => ['required', 'integer'],
        ])['spell_id']);
        if (CharacterSpell::query()->where('character_id', $character->id)->where('spell_id', $spell->id)->exists()) {
            throw ValidationException::withMessages(['spell_id' => 'That spell is already known.']);
        }
        CharacterSpell::create([
            'character_id' => $character->id,
            'spell_id' => $spell->id,
            'source_type' => 'sheet',
        ]);

        return back();
    }

    public function removeSpell(Character $character, CharacterSpell $characterSpell)
    {
        $this->authorizeView($character);
        abort_unless($characterSpell->character_id === $character->id, 404);
        $characterSpell->delete();

        return back();
    }

    public function addSpellTechnique(Request $request, Character $character)
    {
        $this->authorizeView($character);
        $name = $request->validate(['name' => ['required', 'in:'.implode(',', CharacterAdvancement::SPELL_TECHNIQUES)]])['name'];
        $known = $character->derivation_json['spell_techniques'] ?? [];
        if (in_array($name, $known, true)) {
            throw ValidationException::withMessages(['name' => $name.' is already known.']);
        }
        $known[] = $name;
        $derivation = $character->derivation_json ?? [];
        $derivation['spell_techniques'] = $known;
        $character->update(['derivation_json' => $derivation]);

        return back();
    }

    public function removeSpellTechnique(Character $character, string $technique)
    {
        $this->authorizeView($character);
        $known = array_values(array_filter(
            $character->derivation_json['spell_techniques'] ?? [],
            fn ($name) => $name !== $technique,
        ));
        $derivation = $character->derivation_json ?? [];
        $derivation['spell_techniques'] = $known;
        $character->update(['derivation_json' => $derivation]);

        return back();
    }

    public function setCompanion(Request $request, Character $character)
    {
        $this->authorizeView($character);
        $data = $request->validate([
            'name' => ['required', 'string'],
            'aptitude' => ['nullable', 'string'],
        ]);
        $allowed = array_merge(CharacterAdvancement::GREATER_COMPANIONS, CharacterAdvancement::SMALL_COMPANIONS);
        $name = collect($allowed)->first(fn ($row) => strcasecmp($row, $data['name']) === 0);
        if (! $name) {
            throw ValidationException::withMessages(['name' => 'Choose a companion from the list.']);
        }
        $aptitude = null;
        if (in_array($name, CharacterAdvancement::SMALL_COMPANIONS, true)) {
            $aptitude = collect(['Awareness', 'Stealth', 'Athletics'])->first(fn ($row) => strcasecmp($row, (string) ($data['aptitude'] ?? '')) === 0);
            if (! $aptitude) {
                throw ValidationException::withMessages(['aptitude' => 'Choose Awareness, Stealth, or Athletics.']);
            }
        }
        $derivation = $character->derivation_json ?? [];
        $derivation['companion'] = ['name' => $name, 'aptitude' => $aptitude];
        $character->update(['derivation_json' => $derivation]);

        return back();
    }

    public function addTechnique(Request $request, Character $character)
    {
        $this->authorizeView($character);
        $weaponType = WeaponType::query()->with(['item', 'techniques'])->findOrFail($request->validate([
            'weapon_type_id' => ['required', 'integer'],
        ])['weapon_type_id']);
        $item = $weaponType->item;
        if ($item?->created_by && (int) $item->created_by !== (int) $request->user()->id) {
            abort(404);
        }
        $technique = $weaponType->techniques->first();
        if (! $technique || str_contains(strtolower((string) $item?->description), 'counts as')) {
            throw ValidationException::withMessages(['weapon_type_id' => 'Choose a weapon type that lists a technique.']);
        }
        if (CharacterTechnique::query()->where('character_id', $character->id)->where('weapon_type_id', $weaponType->id)->exists()) {
            throw ValidationException::withMessages(['weapon_type_id' => 'That technique is already known for that weapon.']);
        }
        CharacterTechnique::create([
            'character_id' => $character->id,
            'technique_id' => $technique->id,
            'weapon_type_id' => $weaponType->id,
            'source_type' => 'sheet',
        ]);

        return back();
    }

    public function removeTechnique(Character $character, CharacterTechnique $characterTechnique)
    {
        $this->authorizeView($character);
        abort_unless($characterTechnique->character_id === $character->id, 404);
        $characterTechnique->delete();

        return back();
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function weaponTechniques(?\App\Models\User $user)
    {
        return WeaponType::query()
            ->whereHas('techniques')
            ->whereHas('item', fn ($query) => $query->visibleTo($user))
            ->with(['item', 'techniques'])
            ->get()
            ->reject(fn (WeaponType $type) => str_contains(strtolower((string) $type->item?->description), 'counts as'))
            ->map(fn (WeaponType $type) => [
                'id' => $type->id,
                'weapon' => $type->item?->name,
                'technique' => $type->techniques->first()?->name,
                'detail' => $type->techniques->first()?->description,
            ])
            ->sortBy('weapon')
            ->values();
    }

    public function addItem(Request $request, Character $character)
    {
        $this->authorizeView($character);
        $data = $request->validate([
            'item_id' => ['nullable', 'integer', 'exists:items,id'],
            'name' => ['nullable', 'string', 'max:120'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);
        $quantity = (int) ($data['quantity'] ?? 1);
        $name = trim((string) ($data['name'] ?? ''));
        if (empty($data['item_id']) && $name === '') {
            throw ValidationException::withMessages(['name' => 'Choose an item or name a custom one.']);
        }
        $item = ! empty($data['item_id'])
            ? Item::findOrFail($data['item_id'])
            : $this->customItem($request->user()->id, $name);
        $existing = CharacterInventory::query()
            ->where('character_id', $character->id)
            ->where('item_id', $item->id)
            ->whereNull('equipped_slot')
            ->whereNull('custom_name')
            ->first();
        if ($existing && $item->stackable) {
            $existing->update(['quantity' => $existing->quantity + $quantity]);
        } else {
            CharacterInventory::create([
                'character_id' => $character->id,
                'item_id' => $item->id,
                'quantity' => $quantity,
            ]);
        }

        return back();
    }

    public function removeItem(Character $character, CharacterInventory $inventory)
    {
        $this->authorizeView($character);
        abort_unless($inventory->character_id === $character->id, 404);
        $inventory->delete();

        return back();
    }

    public function equip(Character $character, CharacterInventory $inventory)
    {
        $this->authorizeView($character);
        abort_unless($inventory->character_id === $character->id, 404);
        $inventory->loadMissing('item.armor');
        $armor = $inventory->item?->armor;
        if ($inventory->item?->category === 'armor' && $armor) {
            if ($inventory->equipped_slot) {
                $inventory->update(['equipped_slot' => null]);

                return back();
            }
            if (in_array($armor->armor_category, ['light', 'medium', 'heavy'], true)) {
                CharacterInventory::query()
                    ->where('character_id', $character->id)
                    ->whereKeyNot($inventory->id)
                    ->whereNotNull('equipped_slot')
                    ->whereHas('item.armor', fn ($query) => $query->whereIn('armor_category', ['light', 'medium', 'heavy']))
                    ->update(['equipped_slot' => null]);
            }
            $inventory->update(['equipped_slot' => 'worn']);

            return back();
        }
        $inventory->update(['equipped_slot' => $inventory->equipped_slot ? null : 'wielded']);

        return back();
    }

    public function luck(Request $request, Character $character, CombatService $combat)
    {
        $this->authorizeView($character);
        $data = $request->validate(['event_id' => ['required', 'integer']]);
        $event = CombatEvent::findOrFail($data['event_id']);
        $result = $combat->reverseLuck($character, $event);

        return back()->with('roll', $result);
    }

    private function campaignTargets(Character $character): array
    {
        $characters = Character::query()->where('campaign_id', $character->campaign_id)->where('id', '!=', $character->id)->with('resources')->get()
            ->map(fn (Character $row) => [
                'type' => 'character',
                'id' => $row->id,
                'name' => $row->name,
                'wounds' => $row->resources->firstWhere('resource_key', 'wounds')?->current_value,
            ]);
        $creatures = CreatureInstance::query()->where('campaign_id', $character->campaign_id)->get()
            ->map(fn (CreatureInstance $row) => [
                'type' => 'creature',
                'id' => $row->id,
                'name' => $row->name,
                'wounds' => $row->current_wounds,
            ]);

        return $characters->concat($creatures)->values()->all();
    }

    private function customItem(int $userId, string $name): Item
    {
        $item = Item::query()->whereRaw('lower(name) = ?', [strtolower($name)])->orderByRaw('created_by is null desc')->first();
        if ($item) {
            return $item;
        }

        return Item::create([
            'name' => $name,
            'category' => 'gear',
            'stackable' => true,
            'created_by' => $userId,
        ]);
    }

    private function authorizeView(Character $character): void
    {
        $user = request()->user();
        if ($character->user_id === $user->id || $user->is_gm) {
            return;
        }
        $campaign = $character->campaign_id ? Campaign::find($character->campaign_id) : null;
        if ($campaign && CampaignAccess::isGm($user, $campaign)) {
            return;
        }
        abort(403);
    }
}
