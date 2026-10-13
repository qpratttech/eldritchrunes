<?php

namespace App\Http\Controllers;

use App\Domain\Campaigns\CampaignAccess;
use App\Domain\Maps\FogOfWar;
use App\Domain\Combat\CombatService;
use App\Events\TableUpdated;
use App\Models\Campaign;
use App\Models\Character;
use App\Models\Combatant;
use App\Models\ConditionDefinition;
use App\Models\CreatureInstance;
use App\Models\Encounter;
use App\Models\Scene;
use App\Models\SceneToken;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EncounterController extends Controller
{
    public function store(Request $request, Campaign $campaign)
    {
        CampaignAccess::manage($request->user(), $campaign);
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        Encounter::create([
            'campaign_id' => $campaign->id,
            'scene_id' => $campaign->scenes()->value('id'),
            'name' => $data['name'],
            'status' => 'pending',
        ]);

        return back();
    }

    public function addToCombat(Request $request, Campaign $campaign)
    {
        CampaignAccess::manage($request->user(), $campaign);
        $request->validate([
            'character_id' => ['nullable', 'integer', 'required_without:creature_instance_id'],
            'creature_instance_id' => ['nullable', 'integer', 'required_without:character_id'],
        ]);
        $encounter = Encounter::query()->where('campaign_id', $campaign->id)->latest('id')->first()
            ?? Encounter::create([
                'campaign_id' => $campaign->id,
                'scene_id' => $campaign->scenes()->value('id'),
                'name' => 'Skirmish',
                'status' => 'pending',
            ]);

        return $request->filled('character_id')
            ? $this->addCharacter($request, $encounter)
            : $this->addCreature($request, $encounter);
    }

    public function removeFromCombat(Request $request, Campaign $campaign, CombatService $combat)
    {
        CampaignAccess::manage($request->user(), $campaign);
        $request->validate([
            'character_id' => ['nullable', 'integer', 'required_without:creature_instance_id'],
            'creature_instance_id' => ['nullable', 'integer', 'required_without:character_id'],
        ]);
        $encounter = Encounter::query()->where('campaign_id', $campaign->id)->latest('id')->first();
        if (! $encounter) {
            return back();
        }
        $combatant = $request->filled('character_id')
            ? $encounter->combatants()->where('character_id', $request->integer('character_id'))->first()
            : $encounter->combatants()->where('creature_instance_id', $request->integer('creature_instance_id'))->first();
        if ($combatant) {
            $combat->withdraw($encounter, $combatant);
        }

        return back();
    }

    public function addCharacter(Request $request, Encounter $encounter)
    {
        $campaign = $encounter->campaign;
        CampaignAccess::manage($request->user(), $campaign);
        $character = Character::findOrFail($request->validate(['character_id' => ['required', 'integer']])['character_id']);
        if ((int) $character->campaign_id !== (int) $campaign->id) {
            throw ValidationException::withMessages(['character_id' => 'That character is not in this campaign.']);
        }
        if ($encounter->combatants()->where('character_id', $character->id)->exists()) {
            return back();
        }
        $combatant = Combatant::create([
            'encounter_id' => $encounter->id,
            'character_id' => $character->id,
            'controller_user_id' => $character->user_id,
            'team' => 'ally',
        ]);
        $this->token($encounter, $combatant, $character->name);
        if ($encounter->status === 'active') {
            app(CombatService::class)->rollCombatantInitiative($encounter, $combatant);
        }
        event(new TableUpdated($campaign->id));

        return back();
    }

    public function addCreature(Request $request, Encounter $encounter)
    {
        $campaign = $encounter->campaign;
        CampaignAccess::manage($request->user(), $campaign);
        $instance = CreatureInstance::query()->where('campaign_id', $campaign->id)->findOrFail($request->validate(['creature_instance_id' => ['required', 'integer']])['creature_instance_id']);
        if ($encounter->combatants()->where('creature_instance_id', $instance->id)->exists()) {
            return back();
        }
        $combatant = Combatant::create([
            'encounter_id' => $encounter->id,
            'creature_instance_id' => $instance->id,
            'controller_user_id' => $request->user()->id,
            'team' => 'enemy',
        ]);
        $this->token($encounter, $combatant, $instance->name);
        if ($encounter->status === 'active') {
            app(CombatService::class)->rollCombatantInitiative($encounter, $combatant);
        }
        event(new TableUpdated($campaign->id));

        return back();
    }

    public function initiative(Encounter $encounter, CombatService $combat)
    {
        CampaignAccess::manage(request()->user(), $encounter->campaign);
        $combat->rollInitiative($encounter);

        return back();
    }

    public function spend(Request $request, Combatant $combatant, CombatService $combat)
    {
        $this->act($request, $combatant);
        $data = $request->validate([
            'kind' => ['required', 'in:major,minor,free'],
            'note' => ['required', 'string', 'max:240'],
        ]);
        $combat->spend($combatant, $data['kind'], $data['note']);

        return back();
    }

    public function attack(Request $request, Combatant $combatant, CombatService $combat)
    {
        $this->act($request, $combatant);
        $data = $request->validate([
            'target_id' => ['required', 'integer'],
            'item_id' => ['nullable', 'integer'],
            'heavy' => ['nullable', 'boolean'],
        ]);
        $result = $combat->attack($combatant, Combatant::findOrFail($data['target_id']), $data['item_id'] ?? null, $request->boolean('heavy'));

        return back()->with('roll', $result);
    }

    public function damage(Request $request, Combatant $combatant, CombatService $combat)
    {
        CampaignAccess::manage($request->user(), $combatant->encounter()->first()->campaign);
        $data = $request->validate([
            'amount' => ['required', 'integer'],
            'note' => ['nullable', 'string'],
        ]);
        $combat->applyDamage($combatant, (int) $data['amount'], null, $data['note'] ?? 'Damage');

        return back();
    }

    public function condition(Request $request, Combatant $combatant, CombatService $combat)
    {
        CampaignAccess::manage($request->user(), Encounter::find($combatant->encounter_id)->campaign);
        $data = $request->validate([
            'condition_id' => ['required', 'integer'],
            'severity' => ['nullable', 'integer'],
        ]);
        $combat->applyCondition($combatant, ConditionDefinition::findOrFail($data['condition_id']), (int) ($data['severity'] ?? 1));

        return back();
    }

    public function clear(Encounter $encounter)
    {
        CampaignAccess::manage(request()->user(), $encounter->campaign);
        $campaignId = $encounter->campaign_id;
        $combatantIds = $encounter->combatants()->pluck('id');
        $encounter->update(['active_combatant_id' => null]);
        SceneToken::query()->whereIn('combatant_id', $combatantIds)->delete();
        $encounter->events()->update(['reverses_event_id' => null, 'actor_id' => null, 'target_id' => null]);
        $encounter->events()->delete();
        $encounter->combatants()->delete();
        $encounter->delete();
        event(new TableUpdated($campaignId));

        return back()->with('success', 'Combat cleared.');
    }

    public function endTurn(Encounter $encounter, CombatService $combat)
    {
        $active = Combatant::find($encounter->active_combatant_id);
        if ($active) {
            $this->act(request(), $active);
        } else {
            CampaignAccess::manage(request()->user(), $encounter->campaign);
        }
        $combat->endTurn($encounter);

        return back();
    }

    private function act(Request $request, Combatant $combatant): void
    {
        $encounter = Encounter::findOrFail($combatant->encounter_id);
        $user = $request->user();
        if (CampaignAccess::isGm($user, $encounter->campaign) || (int) $combatant->controller_user_id === (int) $user->id) {
            return;
        }
        abort(403);
    }

    private function token(Encounter $encounter, Combatant $combatant, string $name): void
    {
        $sceneId = $encounter->scene_id ?: $encounter->campaign->scenes()->value('id');
        if (! $sceneId) {
            return;
        }
        $scene = Scene::query()->find($sceneId);
        $settings = $scene?->settings_json ?? [];
        $cols = max(1, (int) ($settings['cols'] ?? 80));
        $rows = max(1, (int) ($settings['rows'] ?? 48));
        [$x, $y] = $this->openCellNearCenter($sceneId, $cols, $rows);
        SceneToken::create([
            'scene_id' => $sceneId,
            'combatant_id' => $combatant->id,
            'x' => $x,
            'y' => $y,
            'label' => strtoupper(substr($name, 0, 1)),
        ]);
        if ($scene) {
            FogOfWar::reveal($scene);
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function openCellNearCenter(int $sceneId, int $cols, int $rows): array
    {
        $originX = intdiv($cols, 2);
        $originY = intdiv($rows, 2);
        $taken = array_flip(SceneToken::query()->where('scene_id', $sceneId)->get(['x', 'y'])
            ->map(fn (SceneToken $row) => $row->x.','.$row->y)
            ->all());
        if (! isset($taken["$originX,$originY"])) {
            return [$originX, $originY];
        }
        $limit = max($cols, $rows);
        for ($radius = 1; $radius < $limit; $radius++) {
            for ($dy = -$radius; $dy <= $radius; $dy++) {
                for ($dx = -$radius; $dx <= $radius; $dx++) {
                    if (max(abs($dx), abs($dy)) !== $radius) {
                        continue;
                    }
                    $x = $originX + $dx;
                    $y = $originY + $dy;
                    if ($x < 0 || $y < 0 || $x >= $cols || $y >= $rows || isset($taken["$x,$y"])) {
                        continue;
                    }

                    return [$x, $y];
                }
            }
        }

        return [$originX, $originY];
    }
}
