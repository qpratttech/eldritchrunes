<?php

namespace App\Http\Controllers;

use App\Domain\Campaigns\CampaignAccess;
use App\Domain\Maps\FogOfWar;
use App\Domain\Maps\HexGrid;
use App\Domain\Maps\MapBackgrounds;
use App\Events\TableUpdated;
use App\Models\Encounter;
use App\Models\Scene;
use App\Models\SceneLight;
use App\Models\SceneToken;
use App\Models\SceneWall;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SceneController extends Controller
{
    public function update(Request $request, Scene $scene)
    {
        CampaignAccess::manage($request->user(), $scene->campaign);
        $data = $request->validate([
            'lighting' => ['nullable', 'in:day,twilight,night-gloom'],
            'movement' => ['nullable', 'in:open,turn,locked'],
            'cols' => ['nullable', 'integer', 'min:8', 'max:120'],
            'rows' => ['nullable', 'integer', 'min:8', 'max:80'],
            'background' => ['nullable', 'in:'.implode(',', [...MapBackgrounds::keys(), 'none'])],
            'grid' => ['nullable', 'in:hex,square'],
            'show_grid' => ['nullable', 'boolean'],
            'fog' => ['nullable', 'boolean'],
            'scale' => ['nullable', 'numeric', 'min:0.5', 'max:2'],
        ]);
        $settings = $scene->settings_json ?? [];
        foreach (['lighting', 'movement', 'cols', 'rows', 'grid', 'show_grid'] as $key) {
            if (isset($data[$key])) {
                $settings[$key] = $data[$key];
            }
        }
        if (array_key_exists('background', $data)) {
            if ($data['background'] === 'none' || $data['background'] === null) {
                unset($settings['background']);
            } else {
                $settings['background'] = $data['background'];
            }
        }
        if (array_key_exists('fog', $data)) {
            $settings['fog'] = $request->boolean('fog');
        }
        if (array_key_exists('scale', $data) && $data['scale'] !== null) {
            $settings['scale'] = round((float) $data['scale'], 2);
        }
        $scene->update(['settings_json' => $settings]);
        if (! empty($settings['fog'])) {
            FogOfWar::reveal($scene->fresh());
        }
        event(new TableUpdated($scene->campaign_id));

        return back();
    }

    public function moveToken(Request $request, SceneToken $token)
    {
        $scene = $token->scene()->with('campaign')->first();
        $user = $request->user();
        $gm = CampaignAccess::isGm($user, $scene->campaign);
        $owner = (int) $token->combatant?->controller_user_id === (int) $user->id;
        $movement = $scene->settings_json['movement'] ?? 'open';
        if (! $gm && ! $owner) {
            abort(403);
        }
        if (! $gm && $movement === 'locked') {
            throw ValidationException::withMessages(['map' => 'The GM has locked the tokens.']);
        }
        if (! $gm && $movement === 'turn') {
            $activeId = Encounter::query()
                ->where('campaign_id', $scene->campaign_id)
                ->where('status', 'active')
                ->latest()
                ->value('active_combatant_id');
            if ((int) $token->combatant_id !== (int) $activeId) {
                throw ValidationException::withMessages(['map' => 'You can move this token only on its turn.']);
            }
        }
        $data = $request->validate([
            'x' => ['required', 'integer'],
            'y' => ['required', 'integer'],
        ]);
        $square = ($scene->settings_json['grid'] ?? 'hex') === 'square';
        if (HexGrid::crosses($token->x, $token->y, (int) $data['x'], (int) $data['y'], $this->blockingHexes($scene), true, $square)) {
            return back()->withErrors(['map' => 'Rock and trees block that move.']);
        }
        $token->update($data);
        FogOfWar::reveal($scene->fresh());
        SceneLight::query()->where('token_id', $token->id)->update([
            'x' => (int) $data['x'],
            'y' => (int) $data['y'],
        ]);
        event(new TableUpdated($scene->campaign_id));

        return back();
    }

    public function paint(Request $request, Scene $scene)
    {
        CampaignAccess::manage($request->user(), $scene->campaign);
        $data = $request->validate([
            'x' => ['required_without:cells', 'integer'],
            'y' => ['required_without:cells', 'integer'],
            'cells' => ['nullable', 'array', 'max:500'],
            'cells.*.x' => ['required', 'integer'],
            'cells.*.y' => ['required', 'integer'],
            'mode' => ['required', 'in:move,los,both,erase,fog'],
            'terrain' => ['nullable', 'in:rock,tree,black'],
        ]);
        $cells = collect($data['cells'] ?? [['x' => $data['x'], 'y' => $data['y']]])
            ->map(fn ($cell) => [(int) $cell['x'], (int) $cell['y']])
            ->unique(fn ($cell) => $cell[0].','.$cell[1])
            ->values();
        if ($data['mode'] === 'fog') {
            $settings = $scene->settings_json ?? [];
            $fogged = array_fill_keys($settings['fogged'] ?? [], true);
            $explored = array_fill_keys($settings['explored'] ?? [], true);
            foreach ($cells as [$x, $y]) {
                $key = $x.','.$y;
                $fogged[$key] = true;
                unset($explored[$key]);
            }
            $settings['fogged'] = array_keys($fogged);
            $settings['explored'] = array_keys($explored);
            $scene->update(['settings_json' => $settings]);
            event(new TableUpdated($scene->campaign_id));

            return back();
        }
        $walls = SceneWall::query()->firstOrCreate(
            ['scene_id' => $scene->id, 'wall_type' => 'hex'],
            ['geometry_json' => ['move' => [], 'los' => [], 'terrain' => []]]
        );
        $geometry = $walls->geometry_json ?? ['move' => [], 'los' => [], 'terrain' => []];
        $geometry['terrain'] = $geometry['terrain'] ?? [];
        $fogged = array_fill_keys($scene->settings_json['fogged'] ?? [], true);
        foreach ($cells as [$x, $y]) {
            $key = $x.','.$y;
            foreach (['move', 'los'] as $layer) {
                $geometry[$layer] = array_values(array_filter($geometry[$layer] ?? [], fn ($hex) => $hex !== $key));
            }
            unset($geometry['terrain'][$key]);
            unset($fogged[$key]);
            if ($data['mode'] === 'move' || $data['mode'] === 'both') {
                $geometry['move'][] = $key;
            }
            if ($data['mode'] === 'los' || $data['mode'] === 'both') {
                $geometry['los'][] = $key;
            }
            if ($data['mode'] === 'both') {
                $geometry['terrain'][$key] = $data['terrain'] ?? 'rock';
            }
            if ($data['mode'] === 'erase') {
                $tokenIds = SceneToken::query()
                    ->where('scene_id', $scene->id)
                    ->where('x', $x)
                    ->where('y', $y)
                    ->pluck('id');
                SceneLight::query()
                    ->where('scene_id', $scene->id)
                    ->where(function ($query) use ($x, $y, $tokenIds) {
                        $query->where(fn ($inner) => $inner->where('x', $x)->where('y', $y));
                        if ($tokenIds->isNotEmpty()) {
                            $query->orWhereIn('token_id', $tokenIds);
                        }
                    })
                    ->delete();
            }
        }
        $walls->update(['geometry_json' => $geometry]);
        if ($data['mode'] === 'erase') {
            $settings = $scene->settings_json ?? [];
            $explored = array_fill_keys($settings['explored'] ?? [], true);
            foreach ($cells as [$x, $y]) {
                $explored[$x.','.$y] = true;
            }
            $settings['fogged'] = array_keys($fogged);
            $settings['explored'] = array_keys($explored);
            $scene->update(['settings_json' => $settings]);
        }
        event(new TableUpdated($scene->campaign_id));

        return back();
    }

    public function clearPainted(Scene $scene)
    {
        CampaignAccess::manage(request()->user(), $scene->campaign);
        SceneWall::query()->where('scene_id', $scene->id)->update([
            'geometry_json' => ['move' => [], 'los' => [], 'terrain' => []],
        ]);
        SceneLight::query()->where('scene_id', $scene->id)->delete();
        $settings = $scene->settings_json ?? [];
        unset($settings['fog'], $settings['fogged'], $settings['explored']);
        $scene->update(['settings_json' => $settings]);
        event(new TableUpdated($scene->campaign_id));

        return back()->with('success', 'Painted map marks cleared.');
    }

    public function light(Request $request, Scene $scene)
    {
        CampaignAccess::manage($request->user(), $scene->campaign);
        $data = $request->validate([
            'x' => ['required', 'integer'],
            'y' => ['required', 'integer'],
            'bright_radius_ft' => ['nullable', 'integer'],
            'dim_radius_ft' => ['nullable', 'integer'],
            'name' => ['nullable', 'string'],
        ]);
        $token = SceneToken::query()
            ->where('scene_id', $scene->id)
            ->where('x', $data['x'])
            ->where('y', $data['y'])
            ->latest('id')
            ->first();
        SceneLight::create([
            'scene_id' => $scene->id,
            'token_id' => $token?->id,
            'x' => $data['x'],
            'y' => $data['y'],
            'bright_radius_ft' => $data['bright_radius_ft'] ?? 20,
            'dim_radius_ft' => $data['dim_radius_ft'] ?? 20,
            'name' => $data['name'] ?? 'Light',
            'enabled' => true,
        ]);
        event(new TableUpdated($scene->campaign_id));

        return back();
    }

    /**
     * @return array<int, string>
     */
    private function blockingHexes(Scene $scene): array
    {
        $wall = SceneWall::query()->where('scene_id', $scene->id)->where('wall_type', 'hex')->first();

        return HexGrid::movementCells($wall?->geometry_json ?? []);
    }
}
