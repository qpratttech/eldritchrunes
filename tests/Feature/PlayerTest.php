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
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlayerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_player_can_open_their_page_and_change_their_email(): void
    {
        $player = User::factory()->create(['email' => 'sera@example.com']);
        $other = User::factory()->create(['email' => 'warden@example.com']);

        $this->get('/player')->assertRedirect('/login');

        $this->actingAs($player)->get('/player')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Player/Show', false)
            ->where('auth.user.email', 'sera@example.com')
            ->where('auth.user.name', $player->name)
        );

        $this->actingAs($player)->patch('/player', [
            'email' => 'not-an-email',
        ])->assertSessionHasErrors('email');
        $this->assertSame('sera@example.com', $player->fresh()->email);

        $this->actingAs($player)->patch('/player', [
            'email' => 'warden@example.com',
        ])->assertSessionHasErrors('email');
        $this->assertSame('sera@example.com', $player->fresh()->email);
        $this->assertSame('warden@example.com', $other->fresh()->email);

        $this->actingAs($player)->patch('/player', [
            'email' => 'sera@watchfire.test',
        ])->assertRedirect()->assertSessionHas('success', 'Email saved.');

        $this->assertSame('sera@watchfire.test', $player->fresh()->email);
        $this->actingAs($player)->get('/player')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.email', 'sera@watchfire.test')
        );
    }

    public function test_a_game_master_can_become_a_player(): void
    {
        $gm = User::factory()->create(['is_gm' => true]);
        $player = User::factory()->create(['is_gm' => false]);

        $this->actingAs($player)->delete('/player/role')->assertForbidden();
        $this->assertFalse($player->fresh()->is_gm);

        $this->actingAs($gm)->delete('/player/role')->assertRedirect()->assertSessionHas('success', 'You are a player now.');
        $this->assertFalse($gm->fresh()->is_gm);

        $this->actingAs($gm)->get('/player')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.is_gm', false)
            ->where('can.gm', false)
        );
        $this->actingAs($gm)->get('/npcs')->assertForbidden();
    }

    public function test_a_player_can_become_a_game_master(): void
    {
        $player = User::factory()->create(['is_gm' => false]);
        $gm = User::factory()->create(['is_gm' => true]);

        $this->actingAs($gm)->post('/player/role')->assertForbidden();
        $this->assertTrue($gm->fresh()->is_gm);

        $this->actingAs($player)->post('/player/role')->assertRedirect()->assertSessionHas('success', 'You are a game master now.');
        $this->assertTrue($player->fresh()->is_gm);

        $this->actingAs($player)->get('/player')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.is_gm', true)
            ->where('can.gm', true)
        );
        $this->actingAs($player)->get('/npcs')->assertOk();
    }

    public function test_a_user_has_a_public_page_and_can_write_a_short_description(): void
    {
        $player = User::factory()->create(['name' => 'Sera', 'email' => 'sera@example.com']);
        $other = User::factory()->create();

        $this->get("/players/{$player->id}")->assertRedirect('/login');

        $this->actingAs($other)->get("/players/{$player->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Player/Public', false)
            ->where('profile.name', 'Sera')
            ->where('profile.description', null)
            ->where('profile', fn ($profile) => ! isset($profile['email']))
        );

        $this->actingAs($player)->patch('/player', [
            'email' => 'sera@example.com',
            'description' => str_repeat('a', 501),
        ])->assertSessionHasErrors('description');
        $this->assertNull($player->fresh()->description);

        $this->actingAs($player)->patch('/player', [
            'email' => 'sera@example.com',
            'description' => 'Keeper of the last watchfire.',
        ])->assertRedirect()->assertSessionHas('success', 'Description saved.');

        $this->assertSame('Keeper of the last watchfire.', $player->fresh()->description);
        $this->actingAs($other)->get("/players/{$player->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('profile.description', 'Keeper of the last watchfire.')
            ->where('profile', fn ($profile) => ! isset($profile['email']))
        );

        $this->actingAs($player)->patch('/player', [
            'email' => 'sera@example.com',
            'description' => '   ',
        ])->assertRedirect();
        $this->assertNull($player->fresh()->description);
    }

    public function test_a_player_can_change_their_password(): void
    {
        $player = User::factory()->create([
            'email' => 'sera@example.com',
            'password' => 'old-secret',
        ]);

        $this->actingAs($player)->patch('/player', [
            'email' => 'sera@example.com',
            'password' => 'new-secret',
            'password_confirmation' => 'different',
        ])->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('old-secret', $player->fresh()->password));

        $this->actingAs($player)->patch('/player', [
            'email' => 'sera@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
            'current_password' => 'old-secret',
        ])->assertSessionHasErrors('password');

        $this->actingAs($player)->patch('/player', [
            'email' => 'sera@example.com',
            'current_password' => 'wrong-secret',
            'password' => 'new-secret',
            'password_confirmation' => 'new-secret',
        ])->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('old-secret', $player->fresh()->password));

        $this->actingAs($player)->patch('/player', [
            'email' => 'sera@example.com',
            'current_password' => 'old-secret',
            'password' => 'new-secret',
            'password_confirmation' => 'new-secret',
        ])->assertRedirect()->assertSessionHas('success', 'Password saved.');

        $this->assertTrue(Hash::check('new-secret', $player->fresh()->password));
        $this->assertSame('sera@example.com', $player->fresh()->email);
    }

    public function test_a_player_can_delete_their_account(): void
    {
        $player = User::factory()->create(['email' => 'sera@example.com']);
        $friend = User::factory()->create();
        $owned = Campaign::create(['owner_id' => $player->id, 'name' => 'Ash Road']);
        $shared = Campaign::create(['owner_id' => $friend->id, 'name' => 'Salt Gate']);
        $scene = Scene::create(['campaign_id' => $shared->id, 'name' => 'Watchfire']);
        CampaignMember::create(['campaign_id' => $shared->id, 'user_id' => $player->id, 'role' => 'player']);
        $kept = Character::create(['user_id' => $friend->id, 'campaign_id' => $owned->id, 'name' => 'Warden']);
        $sera = Character::create(['user_id' => $player->id, 'campaign_id' => $shared->id, 'name' => 'Sera']);
        $encounter = Encounter::create(['campaign_id' => $shared->id, 'scene_id' => $scene->id, 'name' => 'Skirmish', 'status' => 'active']);
        $combatant = Combatant::create(['encounter_id' => $encounter->id, 'character_id' => $sera->id, 'team' => 'ally']);
        SceneToken::create(['scene_id' => $scene->id, 'combatant_id' => $combatant->id, 'x' => 1, 'y' => 1, 'label' => 'S']);

        $this->actingAs($player)->delete('/player', [
            'password' => 'wrong-secret',
        ])->assertSessionHasErrors('password');
        $this->assertDatabaseHas('users', ['id' => $player->id]);

        $this->actingAs($player)->delete('/player', [
            'password' => 'password',
        ])->assertRedirect('/login');

        $this->assertDatabaseMissing('users', ['id' => $player->id]);
        $this->assertDatabaseMissing('characters', ['id' => $sera->id]);
        $this->assertDatabaseMissing('campaigns', ['id' => $owned->id]);
        $this->assertDatabaseMissing('campaign_members', ['user_id' => $player->id]);
        $this->assertDatabaseMissing('combatants', ['id' => $combatant->id]);
        $this->assertDatabaseMissing('scene_tokens', ['combatant_id' => $combatant->id]);
        $this->assertNull($kept->fresh()->campaign_id);
        $this->assertDatabaseHas('users', ['id' => $friend->id]);
        $this->assertDatabaseHas('characters', ['id' => $kept->id]);
    }
}
