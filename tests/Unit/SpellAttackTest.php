<?php

namespace Tests\Unit;

use App\Domain\Rules\SpellAttack;
use PHPUnit\Framework\TestCase;

class SpellAttackTest extends TestCase
{
    public function test_a_save_spell_is_recognized_and_a_direct_attack_is_not(): void
    {
        $this->assertSame('Willpower', SpellAttack::forcedSave('Willpower save or fall Prone and Unconscious.'));
        $this->assertSame('Willpower', SpellAttack::forcedSave('The target makes a Willpower save. On failure, it takes 1 Eldritch damage.'));
        $this->assertSame('Endurance', SpellAttack::forcedSave('Each creature makes an Endurance save or takes 1 Cold damage and becomes Chilled.'));
        $this->assertSame(1, SpellAttack::damage('Each creature makes an Endurance save or takes 1 Cold damage and becomes Chilled.'));
        $this->assertNull(SpellAttack::forcedSave('Make a direct spell attack; a hit deals 2 Fire damage. If it inflicts a wound, the target makes a Reflexes save or becomes Burning.'));
        $this->assertNull(SpellAttack::forcedSave('Create a unique palm-sized mark.'));
    }
}
