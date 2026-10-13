<?php

namespace Tests\Unit;

use App\Domain\Combat\ActionPool;
use PHPUnit\Framework\TestCase;

class ActionPoolTest extends TestCase
{
    public function test_a_standard_attack_spends_the_major_action(): void
    {
        $priced = ActionPool::price(1, 2, 0, 0, false);

        $this->assertSame(['major'], $priced['actions']);
        $this->assertSame(0, $priced['major']);
        $this->assertSame(2, $priced['minor']);
    }

    public function test_an_extra_attack_spends_a_minor_action(): void
    {
        $priced = ActionPool::price(0, 2, 0, 1, false);

        $this->assertSame(['minor'], $priced['actions']);
        $this->assertSame(1, $priced['minor']);
        $this->assertSame(1, $priced['extra_used']);
    }

    public function test_a_heavy_two_hander_spends_a_major_and_a_minor_action(): void
    {
        $priced = ActionPool::price(1, 2, 0, 0, true);

        $this->assertSame(['major', 'minor'], $priced['actions']);
        $this->assertSame(0, $priced['major']);
        $this->assertSame(1, $priced['minor']);
    }

    public function test_features_that_grant_an_extra_attack_are_counted(): void
    {
        $this->assertSame(1, ActionPool::extraAttacksIn('You may make one additional attack with your mastered weapon on your turn.'));
        $this->assertSame(0, ActionPool::extraAttacksIn('Each beast companion you control may make one additional attack on its turn.'));
        $this->assertSame(0, ActionPool::extraAttacksIn('This does not grant extra attacks or remove the armed-opponent penalty.'));
    }
}
