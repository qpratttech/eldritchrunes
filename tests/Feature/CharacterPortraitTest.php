<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\Species;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CharacterPortraitTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_character_can_choose_a_catalog_portrait_and_return_to_the_species_portrait(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $species = Species::create(['name' => 'Orc', 'portrait_path' => 'species/orc.webp']);
        $character = Character::create(['user_id' => $user->id, 'name' => 'Vash', 'species_id' => $species->id]);

        $this->actingAs($other)->patch("/characters/{$character->id}/portrait", [
            'portrait_path' => 'portraits/human-warrior.webp',
        ])->assertForbidden();

        $this->actingAs($user)->patch("/characters/{$character->id}/portrait", [
            'portrait_path' => 'characters/secret.webp',
        ])->assertSessionHasErrors('portrait_path');
        $this->assertNull($character->fresh()->portrait_path);

        $this->actingAs($user)->patch("/characters/{$character->id}/portrait", [
            'portrait_path' => 'portraits/human-warrior.webp',
        ])->assertRedirect();
        $character->refresh();
        config(['filesystems.disks.s3.url' => 'https://assets.example.test']);
        $this->assertSame('portraits/human-warrior.webp', $character->portrait_path);
        $this->assertSame('https://assets.example.test/portraits/human-warrior.webp?v=9', $character->portraitUrl());

        $this->actingAs($user)->patch("/characters/{$character->id}/portrait", [
            'portrait_path' => null,
        ])->assertRedirect();
        $character->refresh();
        $this->assertNull($character->portrait_path);
        $this->assertSame('https://assets.example.test/species/orc.webp', $character->portraitUrl());
    }
}
