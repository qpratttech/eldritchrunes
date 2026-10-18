<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignMember;
use App\Models\Character;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CampaignJoinTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_player_can_join_a_public_campaign_and_open_their_character(): void
    {
        $gm = User::factory()->create(['is_gm' => true]);
        $player = User::factory()->create();
        $campaign = Campaign::create([
            'owner_id' => $gm->id,
            'name' => 'Ash Road',
            'is_public' => true,
            'password' => Hash::make('watchword'),
        ]);
        $hidden = Campaign::create([
            'owner_id' => $gm->id,
            'name' => 'Secret Road',
            'is_public' => false,
        ]);
        $character = Character::create(['user_id' => $player->id, 'name' => 'Sera']);

        $this->actingAs($player)->get('/campaigns')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('can.campaigns', true)
            ->where('can.gm', false)
            ->where('publicCampaigns', fn ($rows) => collect($rows)->contains(fn ($row) => $row['name'] === 'Ash Road' && $row['has_password'] === true && $row['gm']['id'] === $gm->id && $row['gm']['name'] === $gm->name)
                && collect($rows)->doesntContain(fn ($row) => $row['name'] === 'Secret Road'))
        );

        $this->actingAs($player)->post('/campaigns', [
            'name' => 'Player Hall',
            'is_public' => true,
        ])->assertForbidden();

        $this->actingAs($player)->post("/campaigns/{$campaign->id}/join", [
            'character_id' => $character->id,
            'password' => 'wrong',
        ])->assertSessionHasErrors('password');

        $this->actingAs($player)->post("/campaigns/{$hidden->id}/join", [
            'character_id' => $character->id,
        ])->assertForbidden();

        $this->actingAs($player)->post("/campaigns/{$campaign->id}/join", [
            'character_id' => $character->id,
            'password' => 'watchword',
        ])->assertRedirect("/campaigns/{$campaign->id}");

        $this->assertSame($campaign->id, $character->fresh()->campaign_id);
        $this->assertTrue(CampaignMember::query()->where('campaign_id', $campaign->id)->where('user_id', $player->id)->where('role', 'player')->exists());

        $this->actingAs($player)->get("/campaigns/{$campaign->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('roster.characters.0.can_open', true)
        );
        $this->actingAs($gm)->get("/campaigns/{$campaign->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('roster.characters.0.can_open', true)
        );
        $this->actingAs($player)->get("/characters/{$character->id}")->assertOk();
        $this->actingAs($gm)->get("/characters/{$character->id}")->assertOk();
    }

    public function test_a_gm_can_add_their_own_character_to_a_private_campaign(): void
    {
        $gm = User::factory()->create(['is_gm' => true]);
        $campaign = Campaign::create([
            'owner_id' => $gm->id,
            'name' => 'Closed Hall',
            'is_public' => false,
            'password' => Hash::make('secret'),
        ]);
        CampaignMember::create([
            'campaign_id' => $campaign->id,
            'user_id' => $gm->id,
            'role' => 'gm',
        ]);
        $character = Character::create(['user_id' => $gm->id, 'name' => 'Warden']);

        $this->actingAs($gm)->get('/campaigns')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('campaigns', fn ($rows) => collect($rows)->contains(
                fn ($row) => $row['name'] === 'Closed Hall' && $row['can_add_character'] === true && $row['is_public'] === false && $row['gm']['id'] === $gm->id && $row['gm']['name'] === $gm->name
            ))
        );

        $this->actingAs($gm)->post("/campaigns/{$campaign->id}/join", [
            'character_id' => $character->id,
        ])->assertRedirect("/campaigns/{$campaign->id}");

        $this->assertSame($campaign->id, $character->fresh()->campaign_id);
        $this->actingAs($gm)->get("/campaigns/{$campaign->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('roster.characters.0.name', 'Warden')
            ->where('roster.characters.0.can_open', true)
        );
    }

    public function test_a_new_campaign_map_starts_as_a_field_in_daylight(): void
    {
        $gm = User::factory()->create(['is_gm' => true]);

        $this->actingAs($gm)->post('/campaigns', [
            'name' => 'Ash Road',
            'is_public' => false,
        ])->assertRedirect();

        $scene = Scene::query()->whereHas('campaign', fn ($query) => $query->where('name', 'Ash Road'))->first();
        $this->assertNotNull($scene);
        $this->assertSame('field', $scene->settings_json['background']);
        $this->assertSame('day', $scene->settings_json['lighting']);
    }
}
