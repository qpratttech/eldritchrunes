<?php

namespace App\Http\Controllers;

use App\Domain\Campaigns\CampaignAccess;
use App\Domain\Characters\CharacterPresenter;
use App\Domain\Combat\ActionPool;
use App\Domain\Combat\AttackLog;
use App\Domain\Combat\CombatService;
use App\Domain\Rules\Percentile;
use App\Models\Campaign;
use App\Models\CampaignMember;
use App\Models\Character;
use App\Models\CharacterResource;
use App\Models\Combatant;
use App\Models\CombatEvent;
use App\Models\CombatTurn;
use App\Models\ConditionDefinition;
use App\Models\CreatureInstance;
use App\Models\CreatureTemplate;
use App\Models\Encounter;
use App\Models\RulesetVersion;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class CampaignController extends Controller
{
    public function index()
    {
        $user = request()->user();
        $campaigns = Campaign::query()
            ->where(function ($query) use ($user) {
                $query->where('owner_id', $user->id)
                    ->orWhereHas('members', fn ($members) => $members->where('user_id', $user->id));
            })
            ->with(['members', 'owner'])
            ->latest()
            ->get()
            ->map(fn (Campaign $campaign) => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'description' => $campaign->description,
                'is_public' => $campaign->is_public,
                'role' => $campaign->owner_id === $user->id ? 'gm' : 'player',
                'can_delete' => CampaignAccess::isGm($user, $campaign),
                'can_add_character' => CampaignAccess::isGm($user, $campaign),
                'gm' => $this->campaignGm($campaign),
            ]);
        $publicCampaigns = Campaign::query()
            ->where('is_public', true)
            ->where('owner_id', '!=', $user->id)
            ->whereDoesntHave('members', fn ($members) => $members->where('user_id', $user->id))
            ->with('owner')
            ->latest()
            ->get()
            ->map(fn (Campaign $campaign) => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'description' => $campaign->description,
                'has_password' => filled($campaign->password),
                'gm' => $this->campaignGm($campaign),
            ]);

        return Inertia::render('Campaigns/Index', [
            'campaigns' => $campaigns,
            'publicCampaigns' => $publicCampaigns,
            'characters' => Character::query()->where('user_id', $user->id)->whereNull('campaign_id')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'is_public' => ['required', 'boolean'],
            'password' => ['nullable', 'string', 'max:120'],
        ]);
        $campaign = Campaign::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_public' => $request->boolean('is_public'),
            'password' => filled($data['password'] ?? null) ? Hash::make($data['password']) : null,
            'owner_id' => $request->user()->id,
            'ruleset_version_id' => RulesetVersion::query()->where('version', '2e')->value('id'),
        ]);
        CampaignMember::create([
            'campaign_id' => $campaign->id,
            'user_id' => $request->user()->id,
            'role' => 'gm',
        ]);
        Scene::create([
            'campaign_id' => $campaign->id,
            'name' => 'Watchfire',
            'grid_type' => 'hex',
            'grid_size_px' => 34,
            'feet_per_cell' => 5,
            'settings_json' => [
                'cols' => 80,
                'rows' => 48,
                'background' => 'field',
                'lighting' => 'day',
            ],
        ]);

        return redirect()->route('campaigns.show', $campaign);
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function campaignGm(Campaign $campaign): ?array
    {
        if (! $campaign->owner) {
            return null;
        }

        return [
            'id' => $campaign->owner->id,
            'name' => $campaign->owner->name,
        ];
    }

    public function join(Request $request, Campaign $campaign)
    {
        $isGm = CampaignAccess::isGm($request->user(), $campaign);
        abort_unless($campaign->is_public || $isGm, 403);
        $data = $request->validate([
            'character_id' => ['required', 'integer'],
            'password' => ['nullable', 'string'],
        ]);
        if (! $isGm && filled($campaign->password) && ! Hash::check((string) ($data['password'] ?? ''), $campaign->password)) {
            throw ValidationException::withMessages(['password' => 'That password does not open this campaign.']);
        }
        $character = Character::query()->where('user_id', $request->user()->id)->findOrFail($data['character_id']);
        if ($character->campaign_id && (int) $character->campaign_id !== (int) $campaign->id) {
            throw ValidationException::withMessages(['character_id' => 'That character is already in a campaign.']);
        }
        $character->update(['campaign_id' => $campaign->id]);
        CampaignMember::query()->firstOrCreate(
            ['campaign_id' => $campaign->id, 'user_id' => $request->user()->id],
            ['role' => 'player'],
        );

        return redirect()->route('campaigns.show', $campaign);
    }

    public function destroy(Campaign $campaign)
    {
        CampaignAccess::manage(request()->user(), $campaign);
        Character::query()->where('campaign_id', $campaign->id)->update(['campaign_id' => null]);
        $campaign->creatures()->delete();
        $campaign->delete();

        return redirect()->route('campaigns.index');
    }

    public function show(Campaign $campaign)
    {
        CampaignAccess::view(request()->user(), $campaign);
        $campaign->load([
            'members.user',
            'scenes.tokens.combatant.character.species',
            'scenes.tokens.combatant.character.features.feature',
            'scenes.tokens.combatant.creature.template',
            'scenes.walls',
            'scenes.lights',
            'creatures.template.actions',
            'creatures.conditions.condition',
        ]);
        $encounter = Encounter::query()
            ->where('campaign_id', $campaign->id)
            ->with(['combatants.character', 'combatants.creature', 'events' => fn ($query) => $query->latest()->limit(40)])
            ->latest()
            ->first();
        $lastTargets = $encounter ? $this->lastAttackTargets($encounter) : [];
        $turn = null;
        if ($encounter?->active_combatant_id) {
            $turn = CombatTurn::query()
                ->where('encounter_id', $encounter->id)
                ->where('combatant_id', $encounter->active_combatant_id)
                ->where('round_number', $encounter->round_number)
                ->first();
        }

        return Inertia::render('Campaigns/Show', [
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'description' => $campaign->description,
                'notes' => $campaign->notes,
                'is_gm' => CampaignAccess::gm(request()->user(), $campaign),
                'members' => $campaign->members->map(fn ($member) => [
                    'id' => $member->id,
                    'name' => $member->user?->name,
                    'role' => $member->role,
                ]),
            ],
            'scene' => $campaign->scenes->first(),
            'encounter' => $encounter ? [
                'id' => $encounter->id,
                'name' => $encounter->name,
                'status' => $encounter->status,
                'round' => $encounter->round_number,
                'active_combatant_id' => $encounter->active_combatant_id,
                'actions' => $turn ? [
                    'major' => $turn->major_remaining,
                    'minor' => $turn->minor_remaining,
                    'free' => $turn->free_remaining,
                ] : null,
                'combatants' => $encounter->combatants->sortBy(fn ($row) => [
                    $row->initiative_total === null ? 1 : 0,
                    -((int) $row->initiative_total),
                    -((int) $row->initiative_tiebreak),
                    $row->id,
                ])->values()->map(fn ($row) => [
                    'id' => $row->id,
                    'name' => $row->character?->name ?? $row->creature?->name,
                    'character_id' => $row->character_id,
                    'creature_instance_id' => $row->creature_instance_id,
                    'last_target' => $lastTargets[$row->id] ?? null,
                    'letter' => strtoupper(substr($row->character?->name ?? $row->creature?->name ?? '?', 0, 1)),
                    'team' => $row->team,
                    'initiative' => $row->initiative_total,
                    'wounds' => $row->character_id
                        ? CharacterResource::query()->where('character_id', $row->character_id)->where('resource_key', 'wounds')->value('current_value')
                        : $row->creature?->current_wounds,
                    'controller_user_id' => $row->controller_user_id,
                ]),
                'events' => $encounter->events->sortBy('id')->values()->map(fn ($event) => [
                    'id' => $event->id,
                    'type' => $event->event_type,
                    'payload' => $event->payload_json,
                    'at' => $event->created_at?->format('H:i:s'),
                ]),
            ] : null,
            'conditions' => ConditionDefinition::query()->where('key', '!=', 'suffering')->orderBy('name')->get(['id', 'name', 'key', 'description']),
            'roster' => [
                'characters' => Character::query()->where('campaign_id', $campaign->id)->with(['conditions.condition', 'user'])->get()->map(function (Character $character) use ($campaign, $encounter) {
                    $sheet = app(CharacterPresenter::class)->present($character);
                    $combatant = $encounter?->combatants->first(fn ($row) => (int) $row->character_id === (int) $character->id);
                    $classes = collect($sheet['classes'])->map(fn ($row) => trim($row['name'].' '.$row['level']))->filter()->implode(' / ');

                    return [
                        'id' => $character->id,
                        'name' => $sheet['name'],
                        'portrait_url' => $sheet['portrait_url'],
                        'can_open' => $character->user_id === request()->user()->id || CampaignAccess::isGm(request()->user(), $campaign),
                        'in_combat' => (bool) $combatant,
                        'combatant_id' => $combatant?->id,
                        'subtitle' => collect([$sheet['species'], $classes])->filter()->implode(' · '),
                        'player' => $character->user ? [
                            'id' => $character->user->id,
                            'name' => $character->user->name,
                        ] : null,
                        'armor' => $this->characterArmor($character),
                        'wounds' => $sheet['resources']['wounds']['current'] ?? null,
                        'max' => $sheet['resources']['wounds']['max'] ?? null,
                        'suffering' => $sheet['suffering'],
                        'initiative' => (int) ($sheet['resources']['initiative_bonus']['max'] ?? 0),
                        'luck' => $sheet['resources']['luck']['current'] ?? 0,
                        'luck_max' => $sheet['resources']['luck']['max'] ?? 0,
                        'stamina' => $sheet['resources']['stamina']['current'] ?? 0,
                        'stamina_max' => $sheet['resources']['stamina']['max'] ?? 0,
                        'power' => $sheet['resources']['sorcerous_power']['current'] ?? 0,
                        'power_max' => $sheet['resources']['sorcerous_power']['max'] ?? 0,
                        'focus' => $sheet['resources']['focus']['current'] ?? 0,
                        'focus_max' => $sheet['resources']['focus']['max'] ?? 0,
                        'xp' => $sheet['xp'] ?? 0,
                        'major_action' => $sheet['resources']['major_action']['current'] ?? 1,
                        'minor_action' => $sheet['resources']['minor_action']['current'] ?? 2,
                        'conditions' => $character->conditions->map(fn ($row) => [
                            'id' => $row->id,
                            'condition_id' => $row->condition_id,
                            'name' => $row->condition?->name,
                            'key' => $row->condition?->key,
                            'detail' => $row->condition?->description,
                        ])->values(),
                        'attacks' => collect($sheet['attacks'])->map(fn ($attack) => [
                            'kind' => $attack['kind'],
                            'name' => $attack['name'],
                            'tier' => $attack['tier'],
                            'item_id' => $attack['item_id'],
                            'spell_id' => $attack['spell_id'],
                            'heavy' => $attack['heavy'],
                            'save' => $attack['save'] ?? null,
                        ])->values(),
                        'saves' => collect($sheet['skills'])->filter(fn ($skill) => in_array($skill['key'], ['endurance', 'reflexes', 'willpower'], true))->map(fn ($skill) => [
                            'id' => $skill['id'],
                            'name' => $skill['name'],
                            'tier' => $skill['tier'],
                        ])->values(),
                        'skills' => collect($sheet['skills'])->filter(fn ($skill) => ! $skill['attack'] && ! in_array($skill['key'], ['endurance', 'reflexes', 'willpower'], true))->map(fn ($skill) => [
                            'id' => $skill['id'],
                            'name' => $skill['name'],
                            'tier' => $skill['tier'],
                        ])->values(),
                    ];
                }),
                'available' => Character::query()->where('user_id', request()->user()->id)->whereNull('campaign_id')->get(['id', 'name']),
                'creatures' => $campaign->creatures
                    ->when(! CampaignAccess::isGm(request()->user(), $campaign), fn ($rows) => $rows->filter(
                        fn ($row) => $encounter?->combatants->contains(fn ($combatant) => (int) $combatant->creature_instance_id === (int) $row->id)
                    )->values())
                    ->map(function ($row) use ($encounter) {
                        $stats = $row->template?->stats_json ?? [];
                        $overrides = $row->overrides_json ?? [];
                        $staminaMax = max(0, (int) ($stats['stamina'] ?? 0));
                        $powerMax = max(0, (int) ($stats['power'] ?? $stats['sorcerous_power'] ?? 0));
                        $focusMax = max(0, (int) ($stats['focus'] ?? 0));

                        return [
                            'id' => $row->id,
                            'name' => $row->name,
                            'combatant_id' => $encounter?->combatants->first(fn ($combatant) => (int) $combatant->creature_instance_id === (int) $row->id)?->id,
                            'in_combat' => (bool) $encounter?->combatants->contains(fn ($combatant) => (int) $combatant->creature_instance_id === (int) $row->id),
                            'portrait_url' => $row->template?->portrait_url,
                            'wounds' => $row->current_wounds,
                            'suffering' => max(0, min(2, (int) ($overrides['suffering'] ?? 0))),
                            'stamina' => max(0, min($staminaMax, (int) ($overrides['stamina'] ?? $staminaMax))),
                            'stamina_max' => $staminaMax,
                            'power' => max(0, min($powerMax, (int) ($overrides['power'] ?? $powerMax))),
                            'power_max' => $powerMax,
                            'focus' => max(0, min($focusMax, (int) ($overrides['focus'] ?? $focusMax))),
                            'focus_max' => $focusMax,
                            'major_action' => max(0, min(1, (int) ($row->overrides_json['major_action'] ?? 1))),
                            'minor_action' => max(0, min(2, (int) ($row->overrides_json['minor_action'] ?? 2))),
                            'max' => $row->template?->base_wounds,
                            'template' => $row->template?->name,
                            'armor' => $row->template?->armor_save_percent,
                            'initiative' => (int) ($row->template?->stats_json['initiative_bonus'] ?? $row->template?->stats_json['initiative'] ?? 0),
                            'attacks' => $row->template?->actions->map(fn ($action) => [
                                'name' => $action->name,
                                'resolution' => $action->resolution_type,
                                'tier' => $action->attack_tier ?? ($row->template->stats_json['attack_tier'] ?? null),
                            ])->values(),
                            'checks' => $this->creatureChecks($row->template),
                            'skills' => $this->creatureSkills($row->template),
                            'conditions' => $row->conditions->map(fn ($condition) => [
                                'id' => $condition->id,
                                'condition_id' => $condition->condition_id,
                                'name' => $condition->condition?->name,
                                'key' => $condition->condition?->key,
                                'detail' => $condition->condition?->description,
                            ])->values(),
                        ];
                    }),
            ],
        ]);
    }

    public function attack(Request $request, Campaign $campaign, CombatService $combat)
    {
        CampaignAccess::manage($request->user(), $campaign);
        $data = $request->validate([
            'creature_instance_id' => ['required', 'integer'],
            'attack' => ['required', 'string'],
            'target_type' => ['required', 'in:character,creature'],
            'target_id' => ['required', 'integer'],
            'circumstance' => ['nullable', 'integer', 'between:-2,2'],
        ]);
        $instance = CreatureInstance::query()->where('campaign_id', $campaign->id)->findOrFail($data['creature_instance_id']);
        $instance->load('template.actions');
        $action = $instance->template->actions->firstWhere('name', $data['attack']);
        $priced = null;
        if ($action && ($action->resolution_type ?? 'attack') !== 'save') {
            $priced = ActionPool::spendCreature($instance, (bool) ($action->effects_json['heavy'] ?? false));
        }
        $shift = ActionPool::noteCreatureAttack($instance);
        [$victimCharacter, $victimCreature, $armor] = $this->victim($campaign, $data['target_type'], (int) $data['target_id']);
        $suffering = max(0, min(2, (int) ($instance->overrides_json['suffering'] ?? 0)));
        $circumstance = max(-2, min(2, (int) ($data['circumstance'] ?? 0) + (($action && ($action->resolution_type ?? 'attack') === 'save') ? -$shift : $shift)));
        $result = $combat->creatureStrike($instance->template, $data['attack'], $armor, $suffering, $circumstance, (bool) $victimCharacter?->preventsArmorPiercing());
        if (is_int($result['damage'] ?? null)) {
            if ($victimCharacter) {
                $resource = CharacterResource::query()->where('character_id', $victimCharacter->id)->where('resource_key', 'wounds')->first();
                if ($resource) {
                    $before = (int) $resource->current_value;
                    $after = max(0, $before - $result['damage']);
                    $resource->update(['current_value' => $after]);
                    $result['target_wounds'] = $after;
                    if ($before > 0 && $after === 0) {
                        ActionPool::defeat($victimCharacter);
                        $result['defeated'] = $victimCharacter->name;
                    }
                }
            }
            if ($victimCreature) {
                $wound = $combat->woundCreature($victimCreature, (int) $result['damage']);
                $result['target_wounds'] = $wound['wounds'];
                if ($wound['defeated']) {
                    $result['defeated'] = $wound['defeated'];
                }
            }
        }
        $result['npc_id'] = $instance->id;
        $result['npc'] = $instance->name;
        $result['attacker'] = $instance->name;
        $result['attacker_suffering'] = $suffering;
        $result['target'] = $victimCharacter?->name ?? $victimCreature?->name;
        $result['target_type'] = $victimCharacter ? 'character' : ($victimCreature ? 'creature' : null);
        $result['target_ref'] = $victimCharacter?->id ?? $victimCreature?->id;
        $result['skill'] = $instance->name;
        $result['note'] = $instance->name.' '.$result['attack'];
        if ($priced) {
            $result['actions'] = $priced['actions'];
        }
        $hit = ($result['success'] ?? false) === true && is_int($result['damage'] ?? null) && ($result['damage'] >= 1 || ! empty($result['armor_saved']));
        if ($hit && $victimCharacter) {
            $result = array_merge($result, $combat->endureWounds($victimCharacter));
        }
        if ($hit && $victimCreature) {
            $result = array_merge($result, $combat->endureCreature($victimCreature));
        }
        $result['summary'] = AttackLog::sentence($result);
        $encounter = Encounter::query()->where('campaign_id', $campaign->id)->latest()->first();
        if ($encounter) {
            $actor = Combatant::query()->where('encounter_id', $encounter->id)->where('creature_instance_id', $instance->id)->first();
            $targetCombatant = null;
            if ($victimCharacter) {
                $targetCombatant = Combatant::query()->where('encounter_id', $encounter->id)->where('character_id', $victimCharacter->id)->first();
            } elseif ($victimCreature) {
                $targetCombatant = Combatant::query()->where('encounter_id', $encounter->id)->where('creature_instance_id', $victimCreature->id)->first();
            }
            $combat->record($encounter, $actor, $targetCombatant, 'attack', $result);
        }

        return back()->with('roll', $result);
    }

    public function dice(Request $request, Campaign $campaign, CombatService $combat)
    {
        CampaignAccess::view($request->user(), $campaign);
        $data = $request->validate([
            'die' => ['required', 'in:percentile,2d6,1d6'],
            'character_id' => ['required_without:creature_instance_id', 'nullable', 'integer'],
            'creature_instance_id' => ['required_without:character_id', 'nullable', 'integer'],
        ]);
        $actor = null;
        if (filled($data['character_id'] ?? null)) {
            $character = Character::query()->where('campaign_id', $campaign->id)->findOrFail($data['character_id']);
            if ($character->user_id !== $request->user()->id && ! CampaignAccess::isGm($request->user(), $campaign)) {
                abort(403);
            }
            $name = $character->name;
            $owner = ['character_id' => $character->id];
        } else {
            CampaignAccess::manage($request->user(), $campaign);
            $instance = CreatureInstance::query()->where('campaign_id', $campaign->id)->findOrFail($data['creature_instance_id']);
            $name = $instance->name;
            $owner = ['npc_id' => $instance->id, 'npc' => $instance->name];
        }
        $result = array_merge($combat->manualDie($name, $data['die']), $owner);
        $encounter = Encounter::query()->where('campaign_id', $campaign->id)->latest('id')->first();
        if ($encounter) {
            $actor = filled($data['character_id'] ?? null)
                ? Combatant::query()->where('encounter_id', $encounter->id)->where('character_id', $data['character_id'])->first()
                : Combatant::query()->where('encounter_id', $encounter->id)->where('creature_instance_id', $data['creature_instance_id'])->first();
            $combat->record($encounter, $actor, null, 'dice', $result);
        }

        return back()->with('roll', $result);
    }

    public function check(Request $request, Campaign $campaign, CombatService $combat)
    {
        CampaignAccess::manage($request->user(), $campaign);
        $data = $request->validate([
            'creature_instance_id' => ['required', 'integer'],
            'name' => ['required', 'string'],
            'circumstance' => ['nullable', 'integer', 'between:-2,2'],
        ]);
        $instance = CreatureInstance::query()->where('campaign_id', $campaign->id)->with('template')->findOrFail($data['creature_instance_id']);
        $entry = collect($this->creatureChecks($instance->template))->merge($this->creatureSkills($instance->template))->firstWhere('name', $data['name']);
        if (! $entry) {
            return back()->withErrors(['roll' => 'That check has no tier to roll.']);
        }
        $suffering = max(0, min(2, (int) ($instance->overrides_json['suffering'] ?? 0)));
        $result = $this->rollTier(max(0, (int) $entry['tier'] - $suffering) + (int) ($data['circumstance'] ?? 0), $data['name']);
        $result['circumstance'] = (int) ($data['circumstance'] ?? 0);
        $result['modifier'] = AttackLog::circumstance($result['circumstance']);
        $result['npc_id'] = $instance->id;
        $result['npc'] = $instance->name;
        $result['skill'] = $data['name'];
        $result['attack'] = null;
        $result['note'] = $instance->name.' '.$data['name'];
        $result['damage'] = null;
        $encounter = Encounter::query()->where('campaign_id', $campaign->id)->latest()->first();
        if ($encounter) {
            $actor = Combatant::query()->where('encounter_id', $encounter->id)->where('creature_instance_id', $instance->id)->first();
            $combat->record($encounter, $actor, null, 'check', $result);
        }

        return back()->with('roll', $result);
    }

    /**
     * @return array<int, string>
     */
    private function lastAttackTargets(Encounter $encounter): array
    {
        $combatants = $encounter->combatants->keyBy('id');
        $last = [];
        $events = CombatEvent::query()
            ->where('encounter_id', $encounter->id)
            ->where('event_type', 'attack')
            ->whereNotNull('actor_id')
            ->orderByDesc('id')
            ->get(['actor_id', 'target_id', 'payload_json']);
        foreach ($events as $event) {
            $actorId = (int) $event->actor_id;
            if (array_key_exists($actorId, $last)) {
                continue;
            }
            $payload = $event->payload_json ?? [];
            $type = $payload['target_type'] ?? null;
            $ref = $payload['target_ref'] ?? null;
            if (! in_array($type, ['character', 'creature'], true) || ! is_numeric($ref)) {
                $target = $combatants->get($event->target_id);
                if ($target?->character_id) {
                    $type = 'character';
                    $ref = $target->character_id;
                } elseif ($target?->creature_instance_id) {
                    $type = 'creature';
                    $ref = $target->creature_instance_id;
                }
            }
            if (! in_array($type, ['character', 'creature'], true) || ! is_numeric($ref)) {
                continue;
            }
            $last[$actorId] = $type.':'.(int) $ref;
        }

        return $last;
    }

    private function characterArmor(Character $character): int
    {
        return $character->armorSavePercent();
    }

    private function creatureChecks(?CreatureTemplate $template): array
    {
        $stats = $template?->stats_json ?? [];
        $rows = [
            'Melee' => $stats['melee'] ?? null,
            'Ranged' => $stats['ranged'] ?? null,
            'Endurance' => $stats['endurance'] ?? null,
            'Reflexes' => $stats['reflexes'] ?? null,
            'Willpower' => $stats['willpower'] ?? null,
            'Other checks' => $stats['other'] ?? null,
        ];

        return collect($rows)->filter(fn ($tier) => is_numeric($tier))->map(fn ($tier, $name) => [
            'name' => $name,
            'tier' => (int) $tier,
        ])->values()->all();
    }

    private function creatureSkills(?CreatureTemplate $template): array
    {
        $other = $template?->stats_json['other'] ?? null;
        if (! is_numeric($other)) {
            return [];
        }

        return collect($template->traits_json['skills'] ?? [])->map(function (array $skill) use ($other) {
            if (is_numeric($skill['tier'] ?? null)) {
                $tier = (int) $skill['tier'];
            } else {
                $enhanced = str_contains(strtolower($skill['tip'] ?? ''), 'enhanced');
                $tier = (int) $other + ($enhanced ? 1 : 0);
            }

            return [
                'name' => $skill['name'],
                'tier' => $tier,
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function rollTier(int $tier, string $name): array
    {
        $percent = Percentile::successPercent($tier);
        $outcome = Percentile::outcome($tier);
        if ($outcome === 'automatic') {
            return ['success' => true, 'display' => 'Auto', 'critical' => false, 'percent' => $percent, 'skill' => $name];
        }
        if ($outcome === 'impossible') {
            return ['success' => false, 'display' => '—', 'critical' => false, 'percent' => null, 'skill' => $name];
        }
        $roll = random_int(1, 100);

        return [
            'success' => $roll <= $percent,
            'display' => Percentile::display($roll),
            'roll' => $roll,
            'critical' => false,
            'can_crit' => false,
            'crit_range' => 3,
            'percent' => $percent,
            'skill' => $name,
        ];
    }

    /**
     * @return array{0: ?Character, 1: ?CreatureInstance, 2: int}
     */
    private function victim(Campaign $campaign, string $type, int $id): array
    {
        if ($type === 'character') {
            $character = Character::query()->where('campaign_id', $campaign->id)->findOrFail($id);

            return [$character, null, $character->armorSavePercent()];
        }

        $creature = CreatureInstance::query()->where('campaign_id', $campaign->id)->with('template')->findOrFail($id);

        return [null, $creature, (int) ($creature->template?->armor_save_percent ?? 0)];
    }

    public function update(Request $request, Campaign $campaign)
    {
        CampaignAccess::manage($request->user(), $campaign);
        $data = $request->validate([
            'notes' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
        ]);
        $campaign->update($data);

        return back();
    }

    public function addMember(Request $request, Campaign $campaign)
    {
        CampaignAccess::manage($request->user(), $campaign);
        $data = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['required', 'in:player,gm'],
        ]);
        $user = User::query()->where('email', $data['email'])->firstOrFail();
        CampaignMember::query()->updateOrCreate(
            ['campaign_id' => $campaign->id, 'user_id' => $user->id],
            ['role' => $data['role']]
        );

        return back()->with('success', $user->name.' joins the company.');
    }

    public function assignCharacter(Request $request, Campaign $campaign)
    {
        CampaignAccess::view($request->user(), $campaign);
        $data = $request->validate(['character_id' => ['required', 'integer']]);
        $character = Character::findOrFail($data['character_id']);
        if ($character->user_id !== $request->user()->id && ! CampaignAccess::isGm($request->user(), $campaign)) {
            abort(403);
        }
        $character->update(['campaign_id' => $campaign->id]);

        return back();
    }
}
