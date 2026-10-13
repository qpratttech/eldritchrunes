<?php

namespace Tests\Feature;

use App\Domain\Characters\CharacterCreator;
use App\Domain\Combat\CombatService;
use App\Models\Background;
use App\Models\CharacterAttribute;
use App\Models\CharacterResource;
use App\Models\CreatureInstance;
use App\Models\CreatureTemplate;
use App\Models\HeroClass;
use App\Models\Item;
use App\Models\Skill;
use App\Models\Species;
use App\Models\User;
use Database\Seeders\RulesetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NpcDefeatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RulesetSeeder::class);
    }

    public function test_a_wounded_npc_makes_an_endurance_check_and_is_defeated_at_zero(): void
    {
        $user = User::factory()->create();
        $attacker = app(CharacterCreator::class)->create($user, [
            'name' => 'Brute',
            'species_id' => Species::query()->where('name', 'Orc')->value('id'),
            'variance_primary' => ['Very Strong', 'Likable'],
            'variance_secondary' => ['Lucky'],
            'background_id' => Background::query()->where('name', 'Soldier')->value('id'),
            'class_id' => HeroClass::query()->where('name', 'Warrior')->value('id'),
            'primary_skills' => ['melee', 'athletics'],
            'other_skills' => ['awareness', 'stealth', 'survival'],
        ]);
        $melee = Skill::query()->where('key', 'melee')->firstOrFail();
        CharacterAttribute::query()->where('character_id', $attacker->id)->where('attribute_id', $melee->attribute_id)->update(['base_step' => 20]);
        $axe = Item::query()->where('name', 'Axe')->firstOrFail();
        $frail = $this->wolf('Grey', -1, 1);
        $hardy = $this->wolf('Bruin', 20, 3);
        $combat = app(CombatService::class);

        $defeat = $combat->sheetAttack($attacker->fresh(), 'weapon', $axe->id, null, false, null, null, null, $frail);
        $this->assertStringContainsString('Grey makes an Endurance check.', $defeat['summary']);
        $this->assertStringContainsString('Failure. Suffering 1.', $defeat['summary']);
        $this->assertStringContainsString('Grey has been defeated.', $defeat['summary']);
        $this->assertSame(0, $frail->fresh()->current_wounds);
        $this->assertSame(1, $frail->fresh()->overrides_json['suffering']);
        $this->assertSame(0, $frail->fresh()->overrides_json['major_action']);

        CharacterResource::query()->where('character_id', $attacker->id)->where('resource_key', 'major_action')->update(['current_value' => 1]);
        $held = $combat->sheetAttack($attacker->fresh(), 'weapon', $axe->id, null, false, null, null, null, $hardy);
        $this->assertStringContainsString('Bruin makes an Endurance check. (100% chance.) Success.', $held['summary']);
        $this->assertStringNotContainsString('defeated', $held['summary']);
        $this->assertSame(0, (int) ($hardy->fresh()->overrides_json['suffering'] ?? 0));
        $this->assertSame(2, $hardy->fresh()->current_wounds);
    }

    private function wolf(string $name, int $endurance, int $wounds): CreatureInstance
    {
        $template = CreatureTemplate::create([
            'name' => $name.' kind',
            'category' => 'beast',
            'base_wounds' => $wounds,
            'stats_json' => ['endurance' => $endurance],
        ]);

        return CreatureInstance::create([
            'creature_template_id' => $template->id,
            'name' => $name,
            'current_wounds' => $wounds,
            'overrides_json' => ['suffering' => 0, 'major_action' => 1, 'minor_action' => 2],
        ]);
    }
}
