<?php

namespace Tests\Unit;

use App\Domain\Combat\AttackDamage;
use PHPUnit\Framework\TestCase;

class AttackDamageTest extends TestCase
{
    public function test_a_normal_hit_deals_one_wound_and_a_critical_adds_more(): void
    {
        $this->assertSame(1, AttackDamage::wounds(1, false, false, false, false, null, false));
        $this->assertSame(2, AttackDamage::wounds(1, false, true, false, false, null, false));
        $this->assertSame(3, AttackDamage::wounds(1, false, true, true, false, null, false));
        $this->assertSame(2, AttackDamage::wounds(1, true, false, false, false, null, false));
    }

    public function test_a_lesser_attack_deals_no_wounds_unless_the_permit_roll_is_low(): void
    {
        $this->assertSame(0, AttackDamage::wounds(1, false, true, false, true, 40, false));
        $this->assertSame(2, AttackDamage::wounds(1, false, true, false, true, 10, false));
    }

    public function test_armor_reduces_two_wounds(): void
    {
        $this->assertSame(0, AttackDamage::wounds(1, false, false, false, false, null, true));
        $this->assertSame(1, AttackDamage::wounds(1, false, true, true, false, null, true));
    }
}
