<?php

namespace Tests\Unit;

use App\Http\Controllers\Front\GiftGuideController;
use PHPUnit\Framework\TestCase;

class GiftGuideAgeTest extends TestCase
{
    public function test_recommendations_respect_minimum_age_and_upper_limits(): void
    {
        $guide = new GiftGuideController;
        $this->assertFalse($guide->matchesAge('3+ godine', 24));
        $this->assertTrue($guide->matchesAge('3+ godine', 36));
        $this->assertFalse($guide->matchesAge('18+ mjeseci', 12));
        $this->assertTrue($guide->matchesAge('18+ mjeseci', 24));
        $this->assertTrue($guide->matchesAge('3–6 godina', 72));
        $this->assertFalse($guide->matchesAge('3–6 godina', 84));
        $this->assertFalse($guide->matchesAge('Nije navedeno', 36));
        $this->assertTrue($guide->matchesAge('1,5+ godina', 18));
        $this->assertFalse($guide->matchesAge('18+ mjeseci', 144));
        $this->assertFalse($guide->matchesAge('3+ godina', 144));
        $this->assertFalse($guide->matchesAge('5+ godina', 144));
        $this->assertFalse($guide->matchesAge('6+ godina', 144));
        $this->assertFalse($guide->matchesAge('18 mjeseci', 72));
        $this->assertTrue($guide->matchesAge('8+ godina', 144));
        $this->assertTrue($guide->matchesAge('3–14 godina', 144));
        $this->assertFalse($guide->matchesAge('7–10 godina', 144));
    }
}
