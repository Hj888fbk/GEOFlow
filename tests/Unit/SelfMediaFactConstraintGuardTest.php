<?php

namespace Tests\Unit;

use App\Services\SelfMedia\SelfMediaFactConstraintGuard;
use PHPUnit\Framework\TestCase;

final class SelfMediaFactConstraintGuardTest extends TestCase
{
    public function test_image_placeholders_are_not_treated_as_fact_numbers(): void
    {
        $guard = new SelfMediaFactConstraintGuard();

        // 数字会带单位归一化（2026 年 → 2026年），占位符编号必须整体剔除。
        $numbers = $guard->extractAllowedNumbers(['正文带占位符【图片1】和【图片23】，真实数字是 2026 年。']);

        $this->assertContains('2026年', $numbers);
        $this->assertNotContains('1', $numbers);
        $this->assertNotContains('23', $numbers);
    }

    public function test_real_numbers_outside_placeholders_are_still_scanned(): void
    {
        $guard = new SelfMediaFactConstraintGuard();

        $numbers = $guard->extractAllowedNumbers(['压力 1.6MPa，占位符【图片2】不受影响']);

        $this->assertContains('1.6mpa', $numbers);
        $this->assertNotContains('2', $numbers);
    }
}
