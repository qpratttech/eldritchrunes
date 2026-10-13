<?php

namespace Tests\Feature;

use App\Domain\Characters\CharacterCreator;
use App\Domain\Combat\CombatService;
use App\Models\Campaign;
use App\Models\CharacterResource;
use App\Models\Combatant;
use App\Models\CreatureInstance;
use App\Models\CreatureAction;
use App\Models\CreatureTemplate;
use App\Models\Encounter;
use App\Models\Feature;
use App\Models\Species;
use App\Models\User;
use Database\Seeders\RulesetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RulesetSeeder::class);
    }

    public function test_spending_an_action_is_written_to_the_combat_log(): void
    {
        [$gm, $character, $encounter, $combatant] = $this->table();
        app(CombatService::class)->rollInitiative($encounter);
        $combatant = $encounter->fresh()->activeCombatant;

        app(CombatService::class)->spend($combatant, 'minor', 'Draw a blade');

        $this->assertDatabaseHas('combat_events', [
            'encounter_id' => $encounter->id,
            'event_type' => 'action',
        ]);
        $this->assertSame(1, $combatant->turns()->first()->minor_remaining);
    }

    public function test_damage_reduces_current_wounds(): void
    {
        [, $character, $encounter, $combatant] = $this->table();
        $before = CharacterResource::query()->where('character_id', $character->id)->where('resource_key', 'wounds')->value('current_value');
        app(CombatService::class)->applyDamage($combatant, 1, null, 'A clean hit');
        $after = CharacterResource::query()->where('character_id', $character->id)->where('resource_key', 'wounds')->value('current_value');

        $this->assertSame($before - 1, $after);
        $this->assertDatabaseHas('combat_events', ['event_type' => 'damage']);
    }

    public function test_campaign_creature_copies_do_not_change_the_tome(): void
    {
        $gm = User::factory()->create(['is_gm' => true]);
        $template = CreatureTemplate::query()->where('name', 'Bear')->first();
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Ash Road']);
        $this->actingAs($gm)->post("/npcs/{$template->id}/copy", [
            'campaign_id' => $campaign->id,
            'name' => 'Old Bruin',
        ])->assertRedirect();

        $instance = CreatureInstance::query()->where('name', 'Old Bruin')->first();
        $this->actingAs($gm)->patch("/npc-instances/{$instance->id}", [
            'name' => 'Scarred Bruin',
            'current_wounds' => 2,
        ]);

        $this->assertSame('Bear', $template->fresh()->name);
        $this->assertSame(5, $template->fresh()->base_wounds);
        $this->assertSame('Scarred Bruin', $instance->fresh()->name);
    }

    public function test_players_cannot_open_the_gm_ledgers(): void
    {
        $player = User::factory()->create(['is_gm' => false]);
        $gm = User::factory()->create(['is_gm' => true]);

        $this->actingAs($player)->get('/npcs')->assertForbidden();
        $this->actingAs($player)->post('/species', [
            'name' => 'Hedgefolk',
            'description' => 'Quick.',
            'primary' => 'Agile',
            'secondary' => 'Stealthy',
        ])->assertRedirect();
        $this->actingAs($gm)->post('/species', [
            'name' => 'Foxfolk',
            'description' => 'Quick people of the hedge.',
            'primary' => 'Agile, Likable',
            'secondary' => 'Nightvision, Stealthy',
        ])->assertRedirect();
        $this->assertDatabaseHas('species', ['name' => 'Foxfolk']);
    }

    public function test_official_catalog_entries_cannot_be_changed_and_homebrew_belongs_to_its_author(): void
    {
        $author = User::factory()->create();
        $other = User::factory()->create(['is_gm' => true]);
        $elf = Species::query()->where('name', 'Elf')->firstOrFail();
        $agile = Feature::query()->where('name', 'Agile')->whereNull('created_by')->firstOrFail();

        $this->actingAs($other)->patch("/species/{$elf->id}", [
            'name' => 'Not Elf',
            'description' => 'Changed.',
        ])->assertForbidden();
        $this->actingAs($author)->delete("/species/{$elf->id}")->assertForbidden();
        $this->actingAs($author)->delete("/features/{$agile->id}")->assertForbidden();
        $this->assertDatabaseHas('species', ['id' => $elf->id, 'name' => 'Elf', 'created_by' => null]);

        $this->actingAs($author)->post('/species', [
            'name' => 'Foxfolk',
            'description' => 'Quick people of the hedge.',
            'primary' => 'Agile',
            'secondary' => 'Stealthy',
        ])->assertRedirect();

        $fox = Species::query()->where('name', 'Foxfolk')->firstOrFail();
        $this->assertSame($author->id, $fox->created_by);

        $this->actingAs($author)->get('/species')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rows', function ($rows) use ($elf, $fox) {
                $editable = collect($rows)->pluck('editable', 'id');

                return $editable[$elf->id] === false && $editable[$fox->id] === true;
            })
        );
        $this->actingAs($other)->get('/species')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rows', fn ($rows) => collect($rows)->doesntContain(fn ($row) => $row['id'] === $fox->id))
        );
        $this->actingAs($author)->post('/items', [
            'name' => 'Hedge knife',
            'description' => 'A short blade for the hedge.',
            'category' => 'weapon',
            'cost_silver' => 4,
            'weight' => 1,
        ])->assertRedirect();
        $knife = \App\Models\Item::query()->where('name', 'Hedge knife')->firstOrFail();
        $this->actingAs($author)->get('/items')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rows', fn ($rows) => collect($rows)->contains(fn ($row) => $row['id'] === $knife->id))
        );
        $this->actingAs($other)->get('/items')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rows', fn ($rows) => collect($rows)->doesntContain(fn ($row) => $row['id'] === $knife->id))
        );

        $this->actingAs($other)->patch("/species/{$fox->id}", [
            'name' => 'Stolen',
            'description' => 'Mine now.',
        ])->assertForbidden();
        $this->actingAs($author)->patch("/species/{$fox->id}", [
            'name' => 'Foxfolk',
            'description' => 'Revised.',
            'primary' => 'Agile',
            'secondary' => 'Stealthy',
        ])->assertRedirect();
        $this->assertSame($author->id, $fox->fresh()->created_by);
        $this->assertSame('Revised.', $fox->fresh()->description);

        $this->actingAs($author)->delete("/species/{$fox->id}")->assertRedirect();
        $this->assertDatabaseMissing('species', ['id' => $fox->id]);
    }

    public function test_skeleton_goblin_archer_and_bandit_mauler_have_full_entries(): void
    {
        $skeleton = CreatureTemplate::query()->where('name', 'Skeleton')->firstOrFail();
        $this->assertSame(2, $skeleton->base_wounds);
        $this->assertSame(15, $skeleton->armor_save_percent);
        $this->assertSame(8, $skeleton->stats_json['melee']);
        $this->assertSame(0, $skeleton->stats_json['stamina']);
        $this->assertSame(8, CreatureAction::query()->where('creature_template_id', $skeleton->id)->where('name', 'Rusted Sword')->value('attack_tier'));
        $this->assertSame('Undead Frame', $skeleton->traits_json['features'][0]['name']);

        $goblin = CreatureTemplate::query()->where('name', 'Goblin Archer')->firstOrFail();
        $this->assertSame(9, $goblin->stats_json['ranged']);
        $this->assertSame(2, $goblin->stats_json['initiative']);
        $this->assertSame(9, CreatureAction::query()->where('creature_template_id', $goblin->id)->where('name', 'Shortbow')->value('attack_tier'));
        $this->assertSame(60, CreatureAction::query()->where('creature_template_id', $goblin->id)->where('name', 'Shortbow')->value('range_ft'));
        $this->assertSame(9, $goblin->traits_json['skills'][0]['tier']);

        $mauler = CreatureTemplate::query()->where('name', 'Bandit Mauler')->firstOrFail();
        $blow = CreatureAction::query()->where('creature_template_id', $mauler->id)->where('name', 'Heavy Blow')->first();
        $this->assertSame(9, $blow->attack_tier);
        $this->assertTrue($blow->effects_json['heavy']);
        $this->assertTrue($blow->effects_json['two_handed']);
        $this->assertSame(3, $mauler->base_wounds);
        $this->assertSame(2, $mauler->stats_json['stamina']);
    }

    public function test_bear_bandit_mauler_and_skeleton_show_their_portraits(): void
    {
        config(['filesystems.disks.s3.url' => 'https://assets.example.test']);
        $gm = User::factory()->create(['is_gm' => true]);

        $this->actingAs($gm)->get('/npcs')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('templates', function ($rows) {
                $portraits = collect($rows)->pluck('portrait_url', 'name');

                return $portraits['Bear'] === 'https://assets.example.test/npcs/bear.webp'
                    && $portraits['Bandit Mauler'] === 'https://assets.example.test/npcs/bandit-mauler.webp'
                    && $portraits['Direwolf'] === 'https://assets.example.test/npcs/direwolf.webp'
                    && $portraits['Elemental'] === 'https://assets.example.test/npcs/elemental-2.webp'
                    && $portraits['Goblin Archer'] === 'https://assets.example.test/npcs/goblin-archer.webp'
                    && $portraits['Grave Servitor'] === 'https://assets.example.test/npcs/grave-servitor.webp'
                    && $portraits['Panther'] === 'https://assets.example.test/npcs/panther.webp'
                    && $portraits['Skeleton'] === 'https://assets.example.test/npcs/skeleton.webp'
                    && $portraits['Small Companion'] === 'https://assets.example.test/npcs/small-companion.webp'
                    && $portraits['Spider of Kadthor'] === 'https://assets.example.test/npcs/spider.webp';
            })
        );
    }

    public function test_species_cards_include_their_portraits(): void
    {
        config(['filesystems.disks.s3.url' => 'https://assets.example.test']);
        $gm = User::factory()->create(['is_gm' => true]);

        $this->actingAs($gm)->get('/species')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rows', function ($rows) {
                $portraits = collect($rows)->pluck('portrait_url', 'name');

                $elf = collect($rows)->firstWhere('name', 'Elf');

                return $portraits['Elf'] === 'https://assets.example.test/species/elf.webp'
                    && $portraits['Halfogre'] === 'https://assets.example.test/species/half-ogre.png'
                    && $portraits['Halforc'] === 'https://assets.example.test/species/half-orc.webp'
                    && collect($elf['features'])->firstWhere('name', 'Nightvision')['rules'] === "Faint light reveals more to you than it does to most.\n\nIgnore Diminishment from dim light. This does not let you see in complete darkness.";
            })
        );
    }

    private function table(): array
    {
        $gm = User::factory()->create(['is_gm' => true]);
        $character = app(CharacterCreator::class)->create($gm, [
            'name' => 'Vash',
            'species_id' => \App\Models\Species::query()->where('name', 'Orc')->value('id'),
            'variance_primary' => ['Very Strong', 'Likable'],
            'variance_secondary' => ['Lucky'],
            'background_id' => \App\Models\Background::query()->where('name', 'Soldier')->value('id'),
            'class_id' => \App\Models\HeroClass::query()->where('name', 'Warrior')->value('id'),
            'primary_skills' => ['melee', 'athletics'],
            'other_skills' => ['awareness', 'stealth', 'survival'],
        ]);
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Ash Road']);
        $character->update(['campaign_id' => $campaign->id]);
        $encounter = Encounter::create(['campaign_id' => $campaign->id, 'name' => 'Ambush', 'status' => 'pending']);
        $combatant = Combatant::create([
            'encounter_id' => $encounter->id,
            'character_id' => $character->id,
            'controller_user_id' => $gm->id,
            'team' => 'ally',
        ]);

        return [$gm, $character, $encounter, $combatant];
    }
}
