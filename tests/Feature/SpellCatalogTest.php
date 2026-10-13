<?php

namespace Tests\Feature;

use App\Models\MagicOrder;
use App\Models\Spell;
use App\Models\User;
use Database\Seeders\RulesetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SpellCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_spellbook_follows_the_eleven_orders(): void
    {
        $this->seed(RulesetSeeder::class);

        $this->assertSame(321, Spell::query()->whereNull('created_by')->count());
        $this->assertSame(11, MagicOrder::query()->count());

        $aegis = Spell::query()->where('name', 'Ancient Aegis')->with('order')->firstOrFail();
        $this->assertSame('Circle', $aegis->order->name);
        $this->assertSame(0, $aegis->tier);
        $this->assertTrue($aegis->requires_focus);
        $this->assertSame('Self', $aegis->targeting_json['text']);
        $this->assertStringContainsString('15% Aegis Save', $aegis->rules_text);
        $this->assertSame('The Aegis Save becomes 25%.', $aegis->empowered_effects_json['text']);

        $mark = Spell::query()->where('name', "Dazgol's Mark")->firstOrFail();
        $this->assertFalse($mark->requires_focus);

        $spirit = MagicOrder::query()->where('name', 'Spirit')->with('castingSkill')->firstOrFail();
        $this->assertSame('Nyarn', $spirit->power_source);
        $this->assertSame('shamanism', $spirit->castingSkill->key);

        $mythic = MagicOrder::query()->where('name', 'Mythic')->with('castingSkill')->firstOrFail();
        $this->assertSame('Finnron', $mythic->power_source);
        $this->assertSame('faith', $mythic->castingSkill->key);

        $user = User::factory()->create();
        $this->actingAs($user)->get('/spells')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rows', fn ($rows) => count($rows) === 321 && collect($rows)->contains(
                fn ($row) => $row['name'] === 'Ancient Aegis'
                    && $row['detail'] === 'Circle · Level 0'
                    && $row['casting_time'] === 'Major action'
                    && $row['empowered'] === 'The Aegis Save becomes 25%.'
            ))
        );
    }
}
