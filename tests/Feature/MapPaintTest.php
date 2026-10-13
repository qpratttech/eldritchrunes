<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignMember;
use App\Models\Character;
use App\Models\Combatant;
use App\Models\Encounter;
use App\Models\Scene;
use App\Models\SceneLight;
use App\Models\SceneToken;
use App\Models\SceneWall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MapPaintTest extends TestCase
{
    use RefreshDatabase;

    public function test_erase_removes_blocks_and_lights_on_a_hex(): void
    {
        $gm = User::factory()->create(['is_gm' => true]);
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Ash Road']);
        $scene = Scene::create(['campaign_id' => $campaign->id, 'name' => 'Watchfire']);
        SceneWall::create([
            'scene_id' => $scene->id,
            'wall_type' => 'hex',
            'geometry_json' => ['move' => ['2,3'], 'los' => ['2,3']],
        ]);
        SceneLight::create([
            'scene_id' => $scene->id,
            'x' => 2,
            'y' => 3,
            'bright_radius_ft' => 20,
            'dim_radius_ft' => 20,
        ]);

        $this->actingAs($gm)->post("/scenes/{$scene->id}/paint", [
            'x' => 2,
            'y' => 3,
            'mode' => 'erase',
        ])->assertRedirect();

        $geometry = SceneWall::query()->where('scene_id', $scene->id)->first()->geometry_json;
        $this->assertSame([], $geometry['move']);
        $this->assertSame([], $geometry['los']);
        $this->assertSame(0, SceneLight::query()->where('scene_id', $scene->id)->count());
    }

    public function test_the_gm_can_set_the_map_background(): void
    {
        $gm = User::factory()->create(['is_gm' => true]);
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Ash Road']);
        $scene = Scene::create([
            'campaign_id' => $campaign->id,
            'name' => 'Watchfire',
            'settings_json' => ['cols' => 12, 'lighting' => 'day'],
        ]);

        $this->actingAs($gm)->patch("/scenes/{$scene->id}", ['background' => 'dungeon'])->assertRedirect();
        $this->assertSame('dungeon', $scene->fresh()->settings_json['background']);
        $this->actingAs($gm)->patch("/scenes/{$scene->id}", ['background' => 'grass'])->assertRedirect();
        $this->assertSame('grass', $scene->fresh()->settings_json['background']);
        $this->actingAs($gm)->patch("/scenes/{$scene->id}", ['background' => 'parchment'])->assertRedirect();
        $this->assertSame('parchment', $scene->fresh()->settings_json['background']);
        $this->assertSame('day', $scene->fresh()->settings_json['lighting']);

        $this->actingAs($gm)->patch("/scenes/{$scene->id}", ['grid' => 'square'])->assertRedirect();
        $this->assertSame('square', $scene->fresh()->settings_json['grid']);
        $this->actingAs($gm)->patch("/scenes/{$scene->id}", ['show_grid' => false])->assertRedirect();
        $this->assertFalse($scene->fresh()->settings_json['show_grid']);
        $this->assertSame('square', $scene->fresh()->settings_json['grid']);
        $this->actingAs($gm)->patch("/scenes/{$scene->id}", ['grid' => 'hex', 'show_grid' => true])->assertRedirect();
        $this->assertSame('hex', $scene->fresh()->settings_json['grid']);
        $this->assertTrue($scene->fresh()->settings_json['show_grid']);

        $this->actingAs($gm)->patch("/scenes/{$scene->id}", ['background' => 'none'])->assertRedirect();
        $this->assertArrayNotHasKey('background', $scene->fresh()->settings_json);
    }

    public function test_the_gm_map_scale_is_shared_with_players(): void
    {
        $gm = User::factory()->create(['is_gm' => true]);
        $player = User::factory()->create();
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Ash Road']);
        CampaignMember::create(['campaign_id' => $campaign->id, 'user_id' => $player->id, 'role' => 'player']);
        $scene = Scene::create([
            'campaign_id' => $campaign->id,
            'name' => 'Watchfire',
            'settings_json' => ['lighting' => 'day'],
        ]);

        $this->actingAs($player)->patch("/scenes/{$scene->id}", ['scale' => 1.5])->assertForbidden();
        $this->actingAs($gm)->patch("/scenes/{$scene->id}", ['scale' => 1.5])->assertRedirect();
        $this->assertEquals(1.5, $scene->fresh()->settings_json['scale']);
        $this->assertSame('day', $scene->fresh()->settings_json['lighting']);
        $this->actingAs($gm)->patch("/scenes/{$scene->id}", ['scale' => 3])->assertSessionHasErrors('scale');

        $this->actingAs($player)->get("/campaigns/{$campaign->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('scene.settings_json.scale', 1.5)
        );
    }

    public function test_block_paints_a_rock_or_tree_and_erase_removes_it(): void
    {
        $gm = User::factory()->create(['is_gm' => true]);
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Ash Road']);
        $scene = Scene::create(['campaign_id' => $campaign->id, 'name' => 'Watchfire']);

        $this->actingAs($gm)->post("/scenes/{$scene->id}/paint", [
            'x' => 4,
            'y' => 5,
            'mode' => 'both',
            'terrain' => 'tree',
        ])->assertRedirect();

        $geometry = SceneWall::query()->where('scene_id', $scene->id)->first()->geometry_json;
        $this->assertSame(['4,5'], $geometry['move']);
        $this->assertSame(['4,5'], $geometry['los']);
        $this->assertSame('tree', $geometry['terrain']['4,5']);
        $this->assertContains('4,5', $geometry['move']);
        $this->assertContains('4,5', $geometry['los']);

        $this->actingAs($gm)->post("/scenes/{$scene->id}/paint", [
            'x' => 4,
            'y' => 5,
            'mode' => 'erase',
        ])->assertRedirect();

        $geometry = SceneWall::query()->where('scene_id', $scene->id)->first()->geometry_json;
        $this->assertArrayNotHasKey('4,5', $geometry['terrain'] ?? []);
    }

    public function test_erase_all_clears_every_painted_mark_and_leaves_tokens(): void
    {
        $gm = User::factory()->create(['is_gm' => true]);
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Ash Road']);
        $scene = Scene::create([
            'campaign_id' => $campaign->id,
            'name' => 'Watchfire',
            'settings_json' => ['fog' => true, 'fogged' => ['1,1'], 'explored' => ['2,2']],
        ]);
        SceneWall::create([
            'scene_id' => $scene->id,
            'wall_type' => 'hex',
            'geometry_json' => ['move' => ['1,1', '2,2'], 'los' => ['3,3']],
        ]);
        SceneLight::create(['scene_id' => $scene->id, 'x' => 4, 'y' => 4, 'bright_radius_ft' => 20, 'dim_radius_ft' => 20]);
        $token = \App\Models\SceneToken::create(['scene_id' => $scene->id, 'x' => 1, 'y' => 1, 'label' => 'A']);

        $this->actingAs($gm)->post("/scenes/{$scene->id}/clear-painted")->assertRedirect();

        $geometry = SceneWall::query()->where('scene_id', $scene->id)->first()->geometry_json;
        $this->assertSame([], $geometry['move']);
        $this->assertSame([], $geometry['los']);
        $this->assertSame(0, SceneLight::query()->where('scene_id', $scene->id)->count());
        $this->assertNotNull($token->fresh());
        $settings = $scene->fresh()->settings_json ?? [];
        $this->assertArrayNotHasKey('fog', $settings);
        $this->assertArrayNotHasKey('fogged', $settings);
        $this->assertArrayNotHasKey('explored', $settings);
    }

    public function test_a_light_placed_on_a_token_moves_with_that_token(): void
    {
        $gm = User::factory()->create(['is_gm' => true]);
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Ash Road']);
        $scene = Scene::create(['campaign_id' => $campaign->id, 'name' => 'Watchfire']);
        $token = \App\Models\SceneToken::create(['scene_id' => $scene->id, 'x' => 2, 'y' => 3, 'label' => 'A']);

        $this->actingAs($gm)->post("/scenes/{$scene->id}/lights", ['x' => 2, 'y' => 3])->assertRedirect();

        $light = SceneLight::query()->where('scene_id', $scene->id)->first();
        $this->assertSame($token->id, $light->token_id);

        $this->actingAs($gm)->patch("/tokens/{$token->id}", ['x' => 6, 'y' => 4])->assertRedirect();

        $light->refresh();
        $this->assertSame(6, $light->x);
        $this->assertSame(4, $light->y);
    }

    public function test_fog_of_war_records_cells_a_character_can_see(): void
    {
        $gm = User::factory()->create(['is_gm' => true]);
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Ash Road']);
        $scene = Scene::create([
            'campaign_id' => $campaign->id,
            'name' => 'Watchfire',
            'settings_json' => ['cols' => 4, 'rows' => 3, 'grid' => 'square'],
        ]);
        $encounter = Encounter::create(['campaign_id' => $campaign->id, 'scene_id' => $scene->id, 'name' => 'Skirmish', 'status' => 'active']);
        $character = Character::create(['user_id' => $gm->id, 'name' => 'Vash']);
        $combatant = Combatant::create(['encounter_id' => $encounter->id, 'character_id' => $character->id, 'team' => 'ally']);
        SceneToken::create(['scene_id' => $scene->id, 'combatant_id' => $combatant->id, 'x' => 0, 'y' => 0, 'label' => 'V']);
        SceneWall::create([
            'scene_id' => $scene->id,
            'wall_type' => 'hex',
            'geometry_json' => ['move' => [], 'los' => ['1,0'], 'terrain' => []],
        ]);

        $this->actingAs($gm)->patch("/scenes/{$scene->id}", ['fog' => true])->assertRedirect();

        $explored = $scene->fresh()->settings_json['explored'];
        $this->assertContains('0,0', $explored);
        $this->assertContains('0,1', $explored);
        $this->assertNotContains('2,0', $explored);
    }

    public function test_dragging_paints_several_cells_at_once(): void
    {
        $gm = User::factory()->create(['is_gm' => true]);
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Ash Road']);
        $scene = Scene::create(['campaign_id' => $campaign->id, 'name' => 'Watchfire', 'settings_json' => ['explored' => ['1,1', '2,1', '3,1']]]);

        $this->actingAs($gm)->post("/scenes/{$scene->id}/paint", [
            'mode' => 'los',
            'cells' => [['x' => 1, 'y' => 1], ['x' => 2, 'y' => 1], ['x' => 3, 'y' => 1]],
        ])->assertRedirect();
        $this->actingAs($gm)->post("/scenes/{$scene->id}/paint", [
            'mode' => 'both',
            'terrain' => 'tree',
            'cells' => [['x' => 4, 'y' => 2], ['x' => 5, 'y' => 2]],
        ])->assertRedirect();
        $this->actingAs($gm)->post("/scenes/{$scene->id}/paint", [
            'mode' => 'fog',
            'cells' => [['x' => 1, 'y' => 1], ['x' => 2, 'y' => 1]],
        ])->assertRedirect();

        $geometry = SceneWall::query()->where('scene_id', $scene->id)->first()->geometry_json;
        $this->assertEqualsCanonicalizing(['1,1', '2,1', '3,1', '4,2', '5,2'], $geometry['los']);
        $this->assertEqualsCanonicalizing(['4,2', '5,2'], $geometry['move']);
        $this->assertSame('tree', $geometry['terrain']['4,2']);

        $this->actingAs($gm)->post("/scenes/{$scene->id}/paint", [
            'mode' => 'both',
            'terrain' => 'black',
            'x' => 6,
            'y' => 2,
        ])->assertRedirect();
        $geometry = SceneWall::query()->where('scene_id', $scene->id)->first()->geometry_json;
        $this->assertSame('black', $geometry['terrain']['6,2']);
        $this->assertContains('6,2', $geometry['move']);
        $this->assertContains('6,2', $geometry['los']);
        $settings = $scene->fresh()->settings_json;
        $this->assertEqualsCanonicalizing(['1,1', '2,1'], $settings['fogged']);
        $this->assertNotContains('1,1', $settings['explored']);
        $this->assertContains('3,1', $settings['explored']);
    }

    public function test_characters_clear_nearby_fog_and_the_gm_can_erase_it(): void
    {
        $gm = User::factory()->create(['is_gm' => true]);
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Ash Road']);
        $scene = Scene::create([
            'campaign_id' => $campaign->id,
            'name' => 'Watchfire',
            'settings_json' => ['cols' => 20, 'rows' => 8, 'grid' => 'square', 'fogged' => ['3,0', '15,0']],
        ]);
        $encounter = Encounter::create(['campaign_id' => $campaign->id, 'scene_id' => $scene->id, 'name' => 'Skirmish', 'status' => 'active']);
        $character = Character::create(['user_id' => $gm->id, 'name' => 'Vash']);
        $combatant = Combatant::create(['encounter_id' => $encounter->id, 'character_id' => $character->id, 'team' => 'ally']);
        $token = SceneToken::create(['scene_id' => $scene->id, 'combatant_id' => $combatant->id, 'x' => 0, 'y' => 0, 'label' => 'V']);

        $this->actingAs($gm)->patch("/tokens/{$token->id}", ['x' => 1, 'y' => 0])->assertRedirect();

        $fogged = $scene->fresh()->settings_json['fogged'];
        $this->assertNotContains('3,0', $fogged);
        $this->assertContains('15,0', $fogged);

        $this->actingAs($gm)->post("/scenes/{$scene->id}/paint", [
            'mode' => 'erase',
            'cells' => [['x' => 15, 'y' => 0]],
        ])->assertRedirect();

        $this->assertNotContains('15,0', $scene->fresh()->settings_json['fogged'] ?? []);
    }
}
