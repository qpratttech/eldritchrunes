<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignMember;
use App\Models\Character;
use App\Models\Combatant;
use App\Models\Encounter;
use App\Models\Scene;
use App\Models\SceneToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CharacterCampaignTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sheet_shows_the_linked_campaign_and_the_owner_can_leave(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $campaign = Campaign::create(['owner_id' => $owner->id, 'name' => 'Ash Road']);
        CampaignMember::create(['campaign_id' => $campaign->id, 'user_id' => $owner->id, 'role' => 'player']);
        $scene = Scene::create(['campaign_id' => $campaign->id, 'name' => 'Watchfire']);
        $character = Character::create([
            'user_id' => $owner->id,
            'campaign_id' => $campaign->id,
            'name' => 'Sera',
        ]);
        $companion = Character::create([
            'user_id' => $owner->id,
            'campaign_id' => $campaign->id,
            'name' => 'Brute',
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
            'character_id' => $character->id,
            'team' => 'ally',
            'initiative_total' => 12,
        ]);
        $otherCombatant = Combatant::create([
            'encounter_id' => $encounter->id,
            'character_id' => $companion->id,
            'team' => 'ally',
            'initiative_total' => 4,
        ]);
        $encounter->update(['active_combatant_id' => $combatant->id]);
        SceneToken::create([
            'scene_id' => $scene->id,
            'combatant_id' => $combatant->id,
            'x' => 4,
            'y' => 4,
            'label' => 'S',
        ]);

        $this->actingAs($owner)->get("/characters/{$character->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('sheet.campaign.id', $campaign->id)
            ->where('sheet.campaign.name', 'Ash Road')
            ->where('sheet.can_leave_campaign', true)
        );

        $this->actingAs($other)->delete("/characters/{$character->id}/campaign")->assertForbidden();
        $this->assertSame($campaign->id, $character->fresh()->campaign_id);

        $this->actingAs($owner)->delete("/characters/{$character->id}/campaign")->assertRedirect();

        $this->assertNull($character->fresh()->campaign_id);
        $this->assertSame($campaign->id, $companion->fresh()->campaign_id);
        $this->assertDatabaseMissing('combatants', ['id' => $combatant->id]);
        $this->assertDatabaseMissing('scene_tokens', ['combatant_id' => $combatant->id]);
        $this->assertSame($otherCombatant->id, $encounter->fresh()->active_combatant_id);
        $this->assertTrue(CampaignMember::query()->where('campaign_id', $campaign->id)->where('user_id', $owner->id)->exists());

        $this->actingAs($owner)->get("/characters/{$character->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('sheet.campaign', null)
            ->where('sheet.can_leave_campaign', false)
        );
    }

    public function test_the_campaign_gm_can_remove_another_players_character(): void
    {
        $gm = User::factory()->create(['is_gm' => true]);
        $player = User::factory()->create();
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Ash Road']);
        $character = Character::create([
            'user_id' => $player->id,
            'campaign_id' => $campaign->id,
            'name' => 'Sera',
        ]);

        $this->actingAs($gm)->get("/characters/{$character->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('sheet.campaign.name', 'Ash Road')
            ->where('sheet.can_leave_campaign', true)
        );

        CampaignMember::create(['campaign_id' => $campaign->id, 'user_id' => $player->id, 'role' => 'player']);

        $this->actingAs($gm)->delete("/characters/{$character->id}/campaign")->assertRedirect();
        $this->assertNull($character->fresh()->campaign_id);
        $this->assertFalse(CampaignMember::query()->where('campaign_id', $campaign->id)->where('user_id', $player->id)->exists());
        $this->actingAs($player)->get("/campaigns/{$campaign->id}")->assertForbidden();
    }

    public function test_a_player_who_leaves_loses_access_and_can_join_again(): void
    {
        $gm = User::factory()->create(['is_gm' => true]);
        $player = User::factory()->create();
        $campaign = Campaign::create([
            'owner_id' => $gm->id,
            'name' => 'Ash Road',
            'is_public' => true,
            'description' => 'The long watch',
        ]);
        $other = Campaign::create([
            'owner_id' => $gm->id,
            'name' => 'Salt Gate',
            'is_public' => true,
        ]);
        CampaignMember::create(['campaign_id' => $campaign->id, 'user_id' => $player->id, 'role' => 'player']);
        $character = Character::create([
            'user_id' => $player->id,
            'campaign_id' => $campaign->id,
            'name' => 'Sera',
        ]);
        $companion = Character::create([
            'user_id' => $player->id,
            'campaign_id' => $campaign->id,
            'name' => 'Brute',
        ]);

        $this->actingAs($player)->delete("/characters/{$character->id}/campaign")->assertRedirect();

        $this->assertNull($character->fresh()->campaign_id);
        $this->assertTrue(CampaignMember::query()->where('campaign_id', $campaign->id)->where('user_id', $player->id)->exists());
        $this->actingAs($player)->get("/campaigns/{$campaign->id}")->assertOk();

        $this->actingAs($player)->delete("/characters/{$companion->id}/campaign")->assertRedirect();

        $this->assertNull($companion->fresh()->campaign_id);
        $this->assertFalse(CampaignMember::query()->where('campaign_id', $campaign->id)->where('user_id', $player->id)->exists());
        $this->actingAs($player)->get("/campaigns/{$campaign->id}")->assertForbidden();
        $this->actingAs($player)->get('/campaigns')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('campaigns', fn ($rows) => collect($rows)->pluck('name')->doesntContain('Ash Road'))
            ->where('publicCampaigns', fn ($rows) => collect($rows)->pluck('name')->sort()->values()->all() === ['Ash Road', 'Salt Gate'])
            ->where('characters', fn ($rows) => collect($rows)->pluck('name')->sort()->values()->all() === ['Brute', 'Sera'])
        );

        $this->actingAs($player)->post("/campaigns/{$campaign->id}/join", [
            'character_id' => $character->id,
        ])->assertRedirect("/campaigns/{$campaign->id}");

        $this->assertSame($campaign->id, $character->fresh()->campaign_id);
        $this->assertNull($companion->fresh()->campaign_id);
        $this->assertTrue(CampaignMember::query()->where('campaign_id', $campaign->id)->where('user_id', $player->id)->where('role', 'player')->exists());
        $this->actingAs($player)->get("/campaigns/{$campaign->id}")->assertOk();

        $this->actingAs($player)->post("/campaigns/{$other->id}/join", [
            'character_id' => $companion->id,
        ])->assertRedirect("/campaigns/{$other->id}");
        $this->assertSame($other->id, $companion->fresh()->campaign_id);
    }

    public function test_an_unlinked_character_has_no_campaign_to_leave(): void
    {
        $owner = User::factory()->create();
        $character = Character::create(['user_id' => $owner->id, 'name' => 'Sera']);

        $this->actingAs($owner)->get("/characters/{$character->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('sheet.campaign', null)
            ->where('sheet.can_leave_campaign', false)
        );

        $this->actingAs($owner)->delete("/characters/{$character->id}/campaign")->assertRedirect();
        $this->assertNull($character->fresh()->campaign_id);
    }
}
