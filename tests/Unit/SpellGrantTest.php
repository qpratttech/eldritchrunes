<?php

namespace Tests\Unit;

use App\Domain\Rules\SpellGrant;
use PHPUnit\Framework\TestCase;

class SpellGrantTest extends TestCase
{
    public function test_it_reads_order_limits_and_order_choices(): void
    {
        $nature = SpellGrant::fromText('Gain access to Nature magic, learn up to two Nature spells, and learn one Weapon Technique.');
        $this->assertSame(2, $nature['count']);
        $this->assertSame(['Nature'], $nature['orders']);
        $this->assertFalse($nature['choose_order']);

        $priest = SpellGrant::fromText('Choose one order, gain access to it, and learn up to three spells from that order.');
        $this->assertSame(3, $priest['count']);
        $this->assertTrue($priest['choose_order']);

        $temple = SpellGrant::fromText('Gain access to one order of magic and learn up to two level-0 spells from it.');
        $this->assertSame(2, $temple['count']);
        $this->assertTrue($temple['choose_order']);
        $this->assertSame(0, $temple['max_tier']);

        $lore = SpellGrant::fromText('Gain access to every order of magic and learn up to three spells from any of them.');
        $this->assertTrue($lore['all_orders']);
        $this->assertSame(3, $lore['count']);

        $this->assertNull(SpellGrant::fromText('Increase Sorcerous Soul by 2 and learn two tier-0 spells.'));
        $this->assertTrue(SpellGrant::choosesOrder('Gain access to one order of magic. This does not grant spells, Soul capacity, or additional Potential.'));
        $this->assertTrue(SpellGrant::choosesOrder('Choose one order containing either spell; gain access to that order.'));
        $this->assertFalse(SpellGrant::choosesOrder('Gain access to Nature magic, learn up to two Nature spells, and learn one Weapon Technique.'));
    }
}
