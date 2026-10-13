<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_front_door_features_the_edition_crest(): void
    {
        config(['filesystems.disks.s3.url' => 'https://assets.example.test']);

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Landing', false)
            ->where('crest', 'https://assets.example.test/branding/eldritch-runes-cover.jpg')
        );
    }

    public function test_a_signed_in_user_goes_to_the_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')->assertRedirect('/dashboard');
    }

    public function test_the_sign_in_page_shares_the_logo_from_the_asset_host(): void
    {
        config(['filesystems.disks.s3.url' => 'https://assets.example.test']);

        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('logo', 'https://assets.example.test/branding/eldritch-runes-logo.webp')
            ->where('art.firelight', 'https://assets.example.test/art/firelight.webp')
            ->where('art.field', 'https://assets.example.test/art/field.jpg')
            ->where('art.grass', 'https://assets.example.test/art/grass.jpg')
            ->where('art.stone', 'https://assets.example.test/art/stone.jpg')
            ->where('art.wastes', 'https://assets.example.test/art/wastes.jpg')
            ->where('art.parchment', 'https://assets.example.test/art/parchment.jpg')
            ->where('art.damage', 'https://assets.example.test/art/damage.webp')
            ->where('art.wounds', 'https://assets.example.test/art/wounds.webp')
            ->where('art.suffering', 'https://assets.example.test/art/suffering.webp')
            ->where('art.stamina', 'https://assets.example.test/art/stamina.webp')
            ->where('art.luck', 'https://assets.example.test/art/luck.webp')
            ->where('art.power', 'https://assets.example.test/art/power.webp')
            ->where('art.focus', 'https://assets.example.test/art/focus.webp')
            ->where('art.potential', 'https://assets.example.test/art/potential.webp')
            ->where('art.soul', 'https://assets.example.test/art/soul.webp')
            ->where('art.crit', 'https://assets.example.test/art/crit.webp')
            ->where('art.init', 'https://assets.example.test/art/init.webp')
            ->where('art.armor', 'https://assets.example.test/art/armor.webp')
            ->where('art.move', 'https://assets.example.test/art/move.webp')
            ->where('art.coin', 'https://assets.example.test/art/coin.webp')
            ->where('art.combat', 'https://assets.example.test/art/combat.webp')
            ->where('art.npcs', 'https://assets.example.test/art/npcs.webp')
            ->where('art.player-characters', 'https://assets.example.test/art/player-characters.webp')
            ->where('art.turn-icon', 'https://assets.example.test/art/turn-icon.webp')
            ->where('art.sigil', 'https://assets.example.test/art/sigil.png')
            ->where('art.percentile-tiers', 'https://assets.example.test/art/percentile-tiers.jpg')
            ->where('mapBackgrounds', ['field', 'dungeon', 'grass', 'stone', 'wastes', 'parchment'])
        );
    }
}
