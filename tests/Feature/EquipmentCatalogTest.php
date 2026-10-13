<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Technique;
use Database\Seeders\RulesetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EquipmentCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_equipment_catalog_records_weights_costs_rules_and_techniques(): void
    {
        $this->seed(RulesetSeeder::class);

        $sword = Item::query()->where('name', 'Sword')->firstOrFail();
        $this->assertSame(50, $sword->cost_silver);
        $this->assertEquals(3, $sword->weight);
        $this->assertStringContainsString('Technique: Parry', $sword->description);
        $this->assertTrue($sword->weapon->properties_json['great'] === false);
        $this->assertSame('Parry', $sword->weapon->techniques()->first()->name);

        $greatsword = Item::query()->where('name', 'Greatsword')->firstOrFail();
        $this->assertTrue($greatsword->weapon->properties_json['great']);
        $this->assertSame(2, $greatsword->weapon->hands_required);

        $knuckles = Item::query()->where('name', 'Knuckles')->firstOrFail();
        $this->assertSame('unarmed', $knuckles->weapon->skill->key);
        $this->assertSame('lesser', $knuckles->weapon->damage_mode);

        $this->assertStringContainsString('Diminished', Technique::query()->where('name', 'Parry')->value('description'));
        $this->assertStringContainsString('Armor Save at 10%', Technique::query()->where('name', 'Armor Piercing')->value('description'));

        $armor = Item::query()->where('name', 'Light armor')->firstOrFail();
        $this->assertEquals(10, $armor->weight);
        $this->assertSame(15, $armor->armor->base_save_percent);

        $draught = Item::query()->where('name', 'Healing draught')->firstOrFail();
        $this->assertSame(50, $draught->cost_silver);
        $this->assertStringContainsString('1 Wound', $draught->description);
    }
}
