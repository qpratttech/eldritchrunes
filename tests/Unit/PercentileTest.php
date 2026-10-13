<?php

namespace Tests\Unit;

use App\Domain\Rules\Percentile;
use PHPUnit\Framework\TestCase;

class PercentileTest extends TestCase
{
    public function test_tier_table_and_edges(): void
    {
        $this->assertSame(59, Percentile::successPercent(10));
        $this->assertSame(1, Percentile::successPercent(0));
        $this->assertSame(99, Percentile::successPercent(17));
        $this->assertSame('automatic', Percentile::outcome(18));
        $this->assertSame('impossible', Percentile::outcome(-1));
        $this->assertSame(10, Percentile::tier(8, 2));
        $this->assertSame(9, Percentile::tier(8, 2, 1, 1, true));
    }

    public function test_luck_reverses_digits_once_rules(): void
    {
        $this->assertSame(28, Percentile::reverseLuck(82));
        $this->assertSame(1, Percentile::reverseLuck(10));
        $this->assertSame(10, Percentile::reverseLuck(1));
        $this->assertNull(Percentile::reverseLuck(100));
    }

    public function test_critical_range_requires_a_successful_roll(): void
    {
        $this->assertTrue(Percentile::isCritical(3, 3, true));
        $this->assertFalse(Percentile::isCritical(4, 3, true));
        $this->assertFalse(Percentile::isCritical(2, 3, false));
        $this->assertTrue(Percentile::isCritical(13, 13, true));
    }
}
