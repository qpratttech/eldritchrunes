<?php

namespace App\Http\Controllers;

use App\Domain\Campaigns\CampaignAccess;
use App\Domain\Combat\ActionPool;
use App\Domain\Combat\AttackDamage;
use App\Domain\Rules\Percentile;
use App\Events\TableUpdated;
use App\Models\Campaign;
use App\Models\Combatant;
use App\Models\CombatantCondition;
use App\Models\ConditionDefinition;
use App\Models\CreatureInstance;
use App\Models\CreatureTemplate;
use App\Models\Encounter;
use App\Models\SceneToken;
use Illuminate\Http\Request;
use Inertia\Inertia;

class NpcController extends Controller
{
    public function index()
    {
        return Inertia::render('Npcs/Index', [
            'templates' => CreatureTemplate::query()->with('actions')->orderBy('name')->get()->map(fn (CreatureTemplate $template) => $this->present($template)),
            'campaigns' => Campaign::query()->where('owner_id', request()->user()->id)->get(['id', 'name']),
        ]);
    }

    private function present(CreatureTemplate $template): array
    {
        $stats = $template->stats_json ?? [];
        $traits = $template->traits_json ?? [];
        $attackTip = 'Creatures have a critical range of 01–03 unless a feature says otherwise.';
        $checks = [
            'Melee' => [$stats['melee'] ?? null, 'Melee attack tier. '.$attackTip],
            'Ranged' => [$stats['ranged'] ?? null, 'Ranged attack tier. '.$attackTip],
            'Attack' => [isset($stats['melee']) || isset($stats['ranged']) ? null : ($stats['attack_tier'] ?? null), 'The tier used for this creature’s attacks. '.$attackTip],
            'Endurance' => [$stats['endurance'] ?? null, 'A ready-to-use Endurance save tier. Suffering and Broken still apply.'],
            'Reflexes' => [$stats['reflexes'] ?? null, 'A ready-to-use Reflexes save tier. Suffering and Broken still apply.'],
            'Willpower' => [$stats['willpower'] ?? null, 'A ready-to-use Willpower save tier. Suffering and Broken still apply.'],
            'Other checks' => [$stats['other'] ?? null, 'Used for checks other than the listed attack and save tiers, unless a listed aptitude says otherwise.'],
        ];

        return [
            'id' => $template->id,
            'name' => $template->name,
            'portrait_url' => $template->portrait_url,
            'category' => $template->category,
            'description' => $template->description,
            'behavior' => $stats['behavior'] ?? null,
            'wounds' => $template->base_wounds,
            'suffering' => max(0, min(2, (int) ($stats['suffering'] ?? 0))),
            'major_action' => max(0, min(1, (int) ($stats['major_action'] ?? 1))),
            'minor_action' => max(0, min(2, (int) ($stats['minor_action'] ?? 2))),
            'stamina' => $stats['stamina'] ?? null,
            'power' => (int) ($stats['power'] ?? $stats['sorcerous_power'] ?? 0),
            'focus' => (int) ($stats['focus'] ?? 0),
            'initiative' => $stats['initiative'] ?? null,
            'armor' => $template->armor_save_percent,
            'armor_note' => $stats['armor_note'] ?? null,
            'move' => $template->major_move_ft.'/'.$template->minor_move_ft.' ft',
            'attributes' => collect($checks)
                ->filter(fn (array $row) => is_numeric($row[0]))
                ->map(function (array $row, string $label) {
                    $percent = Percentile::successPercent((int) $row[0]);

                    return [
                        'name' => $label.' '.$row[0],
                        'key' => $label,
                        'tier' => (int) $row[0],
                        'tip' => $row[1].(is_numeric($percent) ? ' Success chance '.$percent.'%.' : ''),
                        'percent' => $percent,
                    ];
                })->values(),
            'skills' => collect($traits['skills'] ?? [])->map(function (array $skill) use ($stats) {
                $enhanced = str_contains(strtolower($skill['tip'] ?? ''), 'enhanced');
                $tier = is_numeric($skill['tier'] ?? null)
                    ? (int) $skill['tier']
                    : (is_numeric($stats['other'] ?? null) ? (int) $stats['other'] + ($enhanced ? 1 : 0) : null);

                return [
                    'name' => $skill['name'],
                    'tip' => $skill['tip'] ?? '',
                    'tier' => $tier,
                ];
            })->values(),
            'features' => $traits['features'] ?? [],
            'attacks' => $template->actions->map(fn ($action) => [
                'name' => $action->name,
                'tip' => trim(sprintf(
                    '%s action. %s. %s',
                    ucfirst((string) $action->action_cost),
                    $action->range_ft ? $action->range_ft.' ft' : 'No range listed',
                    $action->effects_json['tip'] ?? (($action->effects_json['damage'] ?? 'Damage').' '.($action->resolution_type ?? 'attack'))
                )),
                'tier' => $action->attack_tier ?? ($stats['attack_tier'] ?? null),
                'resolution' => $action->resolution_type ?? 'attack',
                'damage' => (string) ($action->effects_json['damage'] ?? '1'),
                'heavy' => (bool) ($action->effects_json['heavy'] ?? false),
                'two_handed' => (bool) ($action->effects_json['two_handed'] ?? false),
            ])->values(),
        ];
    }

    public function roll(Request $request, CreatureTemplate $template)
    {
        $data = $request->validate([
            'type' => ['required', 'in:attack,skill,check'],
            'name' => ['required', 'string'],
            'circumstance' => ['nullable', 'integer', 'between:-2,2'],
        ]);
        $circumstance = (int) ($data['circumstance'] ?? 0);
        $sheet = $this->present($template->load('actions'));
        $list = match ($data['type']) {
            'attack' => $sheet['attacks'],
            'check' => $sheet['attributes'],
            default => $sheet['skills'],
        };
        $entry = collect($list)->first(fn (array $row) => ($row['key'] ?? $row['name']) === $data['name'] || $row['name'] === $data['name']);
        if (! $entry || ! is_numeric($entry['tier'] ?? null)) {
            return back()->withErrors(['roll' => 'That check has no tier to roll.']);
        }

        if ($data['type'] === 'attack' && ($entry['resolution'] ?? 'attack') === 'attack') {
            ActionPool::spendTemplate($template, (bool) ($entry['heavy'] ?? false));
        }

        if ($data['type'] === 'attack' && ($entry['resolution'] ?? 'attack') === 'save') {
            return back()->with('roll', [
                'npc_id' => $template->id,
                'npc' => $template->name,
                'attack' => $entry['name'],
                'skill' => $template->name,
                'save' => true,
                'display' => '—',
                'success' => null,
                'critical' => false,
                'damage' => null,
                'percent' => null,
            ]);
        }

        $tier = max(0, (int) $entry['tier'] - max(0, min(2, (int) ($template->stats_json['suffering'] ?? 0)))) + $circumstance;
        $percent = Percentile::successPercent($tier);
        $outcome = Percentile::outcome($tier);
        if ($outcome === 'impossible') {
            $result = ['success' => false, 'display' => '—', 'critical' => false, 'damage' => null];
        } elseif ($outcome === 'automatic') {
            $result = ['success' => true, 'display' => 'Auto', 'critical' => false, 'damage' => null];
        } else {
            $roll = random_int(1, 100);
            $success = $roll <= $percent;
            $result = [
                'success' => $success,
                'display' => Percentile::display($roll),
                'critical' => $data['type'] === 'attack' && ($entry['resolution'] ?? 'attack') === 'attack' && Percentile::isCritical($roll, 3, $success),
                'damage' => null,
            ];
            if ($data['type'] === 'attack' && ($entry['resolution'] ?? 'attack') === 'attack' && $success) {
                $base = 1;
                if (preg_match('/(\d+)/', (string) $entry['damage'], $match)) {
                    $base = (int) $match[1];
                }
                $result['damage'] = AttackDamage::wounds($base, (bool) ($entry['heavy'] ?? false), $result['critical'], (bool) ($entry['two_handed'] ?? false), false, null, false);
            }
        }

        return back()->with('roll', array_merge($result, [
            'npc_id' => $template->id,
            'npc' => $template->name,
            'attack' => $data['type'] === 'attack' ? $entry['name'] : null,
            'skill' => $data['type'] === 'attack' ? $template->name : ($entry['key'] ?? $entry['name']),
            'percent' => $percent,
            'save' => ($entry['resolution'] ?? null) === 'save',
            'circumstance' => $circumstance,
            'modifier' => \App\Domain\Combat\AttackLog::circumstance($circumstance),
        ]));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'category' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'base_wounds' => ['required', 'integer', 'min:1'],
            'armor_save_percent' => ['nullable', 'integer', 'min:0', 'max:95'],
            'attack_tier' => ['nullable', 'integer'],
        ]);
        CreatureTemplate::create([
            'name' => $data['name'],
            'category' => $data['category'] ?: 'npc',
            'description' => $data['description'] ?? null,
            'base_wounds' => $data['base_wounds'],
            'armor_save_percent' => $data['armor_save_percent'] ?? 0,
            'stats_json' => ['attack_tier' => (int) ($data['attack_tier'] ?? 6)],
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Creature added to the tome.');
    }

    public function update(Request $request, CreatureTemplate $template)
    {
        if (! $request->exists('name') && ($request->exists('suffering') || $request->exists('major_action') || $request->exists('minor_action'))) {
            $data = $request->validate([
                'suffering' => ['sometimes', 'integer', 'min:0', 'max:2'],
                'major_action' => ['sometimes', 'integer', 'min:0', 'max:1'],
                'minor_action' => ['sometimes', 'integer', 'min:0', 'max:2'],
            ]);
            $stats = $template->stats_json ?? [];
            foreach (['suffering', 'major_action', 'minor_action'] as $key) {
                if (array_key_exists($key, $data)) {
                    $stats[$key] = (int) $data[$key];
                }
            }
            if (array_key_exists('major_action', $data) && (int) $data['major_action'] === 1) {
                $stats['extra_attacks_used'] = 0;
            }
            $template->update(['stats_json' => $stats]);

            return back();
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'base_wounds' => ['required', 'integer', 'min:1'],
            'armor_save_percent' => ['nullable', 'integer'],
        ]);
        $template->update($data);

        return back();
    }

    public function copy(Request $request, CreatureTemplate $template)
    {
        $data = $request->validate([
            'campaign_id' => ['required', 'integer'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);
        $campaign = Campaign::findOrFail($data['campaign_id']);
        abort_unless($campaign->owner_id === $request->user()->id || $request->user()->is_gm, 403);
        $instance = CreatureInstance::create([
            'creature_template_id' => $template->id,
            'campaign_id' => $campaign->id,
            'name' => $data['name'] ?: $template->name,
            'current_wounds' => $template->base_wounds,
            'overrides_json' => [],
        ]);

        return back()->with('success', $instance->name.' copied into the campaign.');
    }

    public function updateInstance(Request $request, CreatureInstance $instance)
    {
        abort_unless($request->user()->is_gm, 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'current_wounds' => ['required', 'integer', 'min:0'],
            'suffering' => ['nullable', 'integer', 'min:0', 'max:2'],
            'major_action' => ['nullable', 'integer', 'min:0', 'max:1'],
            'minor_action' => ['nullable', 'integer', 'min:0', 'max:2'],
            'stamina' => ['nullable', 'integer', 'min:0'],
            'power' => ['nullable', 'integer', 'min:0'],
            'focus' => ['nullable', 'integer', 'min:0'],
        ]);
        $stats = $instance->template?->stats_json ?? [];
        $caps = [
            'stamina' => max(0, (int) ($stats['stamina'] ?? 0)),
            'power' => max(0, (int) ($stats['power'] ?? $stats['sorcerous_power'] ?? 0)),
            'focus' => max(0, (int) ($stats['focus'] ?? 0)),
        ];
        $overrides = $instance->overrides_json ?? [];
        foreach (['suffering', 'major_action', 'minor_action', 'stamina', 'power', 'focus'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null) {
                $value = (int) $data[$key];
                $overrides[$key] = array_key_exists($key, $caps) ? min($caps[$key], $value) : $value;
            }
        }
        if (array_key_exists('major_action', $data) && (int) $data['major_action'] === 1) {
            $overrides['extra_attacks_used'] = 0;
        }
        $instance->update([
            'name' => $data['name'],
            'current_wounds' => $data['current_wounds'],
            'overrides_json' => $overrides,
        ]);

        return back();
    }

    public function addCondition(Request $request, CreatureInstance $instance)
    {
        abort_unless($request->user()->is_gm, 403);
        $condition = ConditionDefinition::query()->findOrFail($request->validate([
            'condition_id' => ['required', 'integer'],
        ])['condition_id']);
        abort_if($condition->key === 'suffering', 404);
        CombatantCondition::query()->firstOrCreate([
            'creature_instance_id' => $instance->id,
            'condition_id' => $condition->id,
        ], ['severity' => 1]);
        event(new TableUpdated($instance->campaign_id));

        return back();
    }

    public function removeCondition(CreatureInstance $instance, CombatantCondition $combatantCondition)
    {
        abort_unless(request()->user()->is_gm, 403);
        abort_unless((int) $combatantCondition->creature_instance_id === (int) $instance->id, 404);
        abort_if($combatantCondition->condition?->key === 'suffering', 404);
        $combatantCondition->delete();
        event(new TableUpdated($instance->campaign_id));

        return back();
    }

    public function destroyInstance(CreatureInstance $instance)
    {
        $campaign = $instance->campaign;
        abort_unless($campaign, 404);
        CampaignAccess::manage(request()->user(), $campaign);
        $combatantIds = Combatant::query()->where('creature_instance_id', $instance->id)->pluck('id');
        if ($combatantIds->isNotEmpty()) {
            Encounter::query()->whereIn('active_combatant_id', $combatantIds)->update(['active_combatant_id' => null]);
            SceneToken::query()->whereIn('combatant_id', $combatantIds)->delete();
            Combatant::query()->whereIn('id', $combatantIds)->delete();
        }
        $instance->delete();
        event(new TableUpdated($campaign->id));

        return back();
    }
}
