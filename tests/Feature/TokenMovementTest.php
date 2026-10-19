<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Character;
use App\Models\Combatant;
use App\Models\CreatureInstance;
use App\Models\CreatureTemplate;
use App\Models\Encounter;
use App\Models\Scene;
use App\Models\SceneToken;
use App\Models\SceneWall;
use App\Models\Species;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TokenMovementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_player_can_drag_the_token_they_control(): void
    {
        [$player, $token] = $this->table('open');

        $this->actingAs($player)->patch("/tokens/{$token->id}", ['x' => 4, 'y' => 2])->assertRedirect();

        $this->assertSame(4, $token->fresh()->x);
        $this->assertSame(2, $token->fresh()->y);
    }

    public function test_a_player_cannot_drag_someone_elses_token(): void
    {
        [$player, $token, $gm] = $this->table('open');
        $other = User::factory()->create();

        $this->actingAs($other)->patch("/tokens/{$token->id}", ['x' => 4, 'y' => 2])->assertForbidden();
        $this->actingAs($gm)->patch("/tokens/{$token->id}", ['x' => 5, 'y' => 3])->assertRedirect();
        $this->assertSame(5, $token->fresh()->x);
    }

    public function test_locked_tokens_stay_put_for_players_and_can_still_be_moved_by_the_gm(): void
    {
        [$player, $token, $gm] = $this->table('locked');

        $this->actingAs($player)->patch("/tokens/{$token->id}", ['x' => 4, 'y' => 2])->assertSessionHasErrors('map');
        $this->assertSame(1, $token->fresh()->x);

        $this->actingAs($gm)->patch("/tokens/{$token->id}", ['x' => 4, 'y' => 2])->assertRedirect();
        $this->assertSame(4, $token->fresh()->x);
    }

    public function test_turn_movement_allows_only_the_active_token(): void
    {
        [$player, $token, , $encounter, $combatant] = $this->table('turn');
        $waiting = Combatant::create([
            'encounter_id' => $encounter->id,
            'controller_user_id' => $player->id,
            'team' => 'ally',
        ]);
        $encounter->update(['active_combatant_id' => $waiting->id]);

        $this->actingAs($player)->patch("/tokens/{$token->id}", ['x' => 4, 'y' => 2])->assertSessionHasErrors('map');
        $this->assertSame(1, $token->fresh()->x);

        $encounter->update(['active_combatant_id' => $combatant->id]);
        $this->actingAs($player)->patch("/tokens/{$token->id}", ['x' => 4, 'y' => 2])->assertRedirect();
        $this->assertSame(4, $token->fresh()->x);
    }

    public function test_a_rock_or_tree_blocks_movement_onto_and_through_that_hex(): void
    {
        [$player, $token] = $this->table('open');
        SceneWall::create([
            'scene_id' => $token->scene_id,
            'wall_type' => 'hex',
            'geometry_json' => [
                'move' => ['2,1'],
                'los' => ['2,1'],
                'terrain' => ['2,1' => 'tree'],
            ],
        ]);

        $this->actingAs($player)->patch("/tokens/{$token->id}", ['x' => 2, 'y' => 1])->assertSessionHasErrors('map');
        $this->assertSame(1, $token->fresh()->x);

        $this->actingAs($player)->patch("/tokens/{$token->id}", ['x' => 3, 'y' => 1])->assertSessionHasErrors('map');
        $this->assertSame(1, $token->fresh()->x);

        $this->actingAs($player)->patch("/tokens/{$token->id}", ['x' => 1, 'y' => 3])->assertRedirect();
        $this->assertSame(3, $token->fresh()->y);
    }

    public function test_map_tokens_use_character_and_npc_art_when_it_exists(): void
    {
        config(['filesystems.disks.s3.url' => 'https://assets.example.test']);
        [$player, $token, $gm, $encounter, $combatant] = $this->table('open');
        $species = Species::create(['name' => 'Orc', 'portrait_path' => 'species/orc.webp']);
        $character = Character::create([
            'user_id' => $player->id,
            'name' => 'Vash',
            'species_id' => $species->id,
        ]);
        $combatant->update(['character_id' => $character->id]);
        $template = CreatureTemplate::create(['name' => 'Bear', 'portrait_path' => 'npcs/bear.webp']);
        $bear = CreatureInstance::create([
            'creature_template_id' => $template->id,
            'campaign_id' => $encounter->campaign_id,
            'name' => 'Bear',
            'current_wounds' => 5,
        ]);
        $beast = Combatant::create([
            'encounter_id' => $encounter->id,
            'creature_instance_id' => $bear->id,
            'controller_user_id' => $gm->id,
            'team' => 'enemy',
        ]);
        SceneToken::create([
            'scene_id' => $token->scene_id,
            'combatant_id' => $beast->id,
            'x' => 3,
            'y' => 3,
            'label' => 'B',
        ]);
        SceneToken::create([
            'scene_id' => $token->scene_id,
            'x' => 4,
            'y' => 4,
            'label' => 'Z',
        ]);

        $this->actingAs($gm)->get("/campaigns/{$encounter->campaign_id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('scene.tokens', function ($tokens) {
                $rows = collect($tokens)->keyBy('label');

                return $rows['P']['portrait_url'] === 'https://assets.example.test/species/orc.webp?v=2'
                    && $rows['P']['name'] === 'Vash'
                    && $rows['B']['portrait_url'] === 'https://assets.example.test/npcs/bear.webp'
                    && $rows['B']['name'] === 'Bear'
                    && $rows['B']['footprint'] === 2
                    && $rows['P']['footprint'] === 1
                    && $rows['Z']['portrait_url'] === null
                    && $rows['Z']['name'] === 'Z';
            })
        );
    }

    public function test_a_character_token_reports_nightvision(): void
    {
        [$player, $token, , , $combatant] = $this->table('open');
        $this->assertFalse($token->fresh()->nightvision);

        $species = Species::create(['name' => 'Elf']);
        $character = Character::create([
            'user_id' => $player->id,
            'name' => 'Liri',
            'species_id' => $species->id,
            'derivation_json' => ['flags' => ['nightvision']],
        ]);
        $combatant->update(['character_id' => $character->id]);

        $this->assertTrue($token->fresh()->nightvision);
    }

    private function table(string $movement): array
    {
        $gm = User::factory()->create(['is_gm' => true]);
        $player = User::factory()->create();
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Ash Road']);
        $scene = Scene::create([
            'campaign_id' => $campaign->id,
            'name' => 'Watchfire',
            'settings_json' => ['movement' => $movement, 'cols' => 12, 'rows' => 8],
        ]);
        $encounter = Encounter::create([
            'campaign_id' => $campaign->id,
            'scene_id' => $scene->id,
            'name' => 'Skirmish',
            'status' => 'active',
            'round_number' => 1,
        ]);
        $combatant = Combatant::create([
            'encounter_id' => $encounter->id,
            'controller_user_id' => $player->id,
            'team' => 'ally',
        ]);
        $encounter->update(['active_combatant_id' => $combatant->id]);
        $token = SceneToken::create([
            'scene_id' => $scene->id,
            'combatant_id' => $combatant->id,
            'x' => 1,
            'y' => 1,
            'label' => 'P',
        ]);

        return [$player, $token, $gm, $encounter, $combatant];
    }
}
