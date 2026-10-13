<?php

namespace Tests\Unit;

use App\Models\Feature;
use PHPUnit\Framework\TestCase;

class FeatureTextTest extends TestCase
{
    public function test_rules_text_drops_markdown_escapes_and_bold_markers(): void
    {
        $this->assertSame(
            'Increase maximum Sorcerous Power by 1. Prerequisite: Deadly.',
            Feature::readable('Increase maximum Sorcerous Power by 1\. **Prerequisite:** Deadly.')
        );
    }
}
