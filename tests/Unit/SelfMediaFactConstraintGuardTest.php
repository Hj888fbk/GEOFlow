<?php

namespace Tests\Unit;

use App\Services\SelfMedia\SelfMediaFactConstraintGuard;
use PHPUnit\Framework\TestCase;

final class SelfMediaFactConstraintGuardTest extends TestCase
{
    public function test_image_placeholders_are_not_treated_as_fact_numbers(): void
    {
        $guard = new SelfMediaFactConstraintGuard();

        // 数字会带单位归一化（2026 年 → 2026），占位符编号必须整体剔除。
        $numbers = $guard->extractAllowedNumbers(['正文带占位符【图片1】和【图片23】，真实数字是 2026 年。']);

        $this->assertContains('2026', $numbers);
        $this->assertNotContains('1', $numbers);
        $this->assertNotContains('23', $numbers);
    }

    public function test_real_numbers_outside_placeholders_are_still_scanned(): void
    {
        $guard = new SelfMediaFactConstraintGuard();

        $numbers = $guard->extractAllowedNumbers(['压力 1.6MPa，占位符【图片2】不受影响']);

        $this->assertContains('1.6', $numbers);
        $this->assertNotContains('2', $numbers);
    }

    public function test_html_media_img_tags_are_not_treated_as_fact_numbers(): void
    {
        $guard = new SelfMediaFactConstraintGuard();

        // body_html 里的媒体图标签（data-geoflow-media-key 含十六进制片段）不能被当成事实数字。
        $html = '正文段落。<img data-geoflow-media-key="m_22ed34aa54f1726bcaa5cd92" src="https://x/y">'
            .'以及 <img data-geoflow-media-key="m_04f98d3a4e1058675801a274">';
        $numbers = $guard->extractAllowedNumbers([$html]);

        $this->assertNotContains('22', $numbers);
        $this->assertNotContains('04', $numbers);
    }

    public function test_year_with_and_without_unit_normalize_to_same_number(): void
    {
        $guard = new SelfMediaFactConstraintGuard();

        // "2026年" 与 "2026" 是同一数值，单位不参与事实数字比较。
        $numbers = $guard->extractAllowedNumbers(['母稿标题：2026年橡胶软接头生产厂家选择参考。平台标题：2026橡胶软接头厂家怎么选？']);

        $this->assertContains('2026', $numbers);
        $this->assertNotContains('2026年', $numbers);
    }

    public function test_variant_without_contact_info_passes(): void
    {
        $guard = new SelfMediaFactConstraintGuard();

        $guard->assertNoContactInfo([
            'title' => '2026橡胶软接头厂家怎么选？',
            'summary' => '按工况、生产与检验分层比较。',
            'body_plain' => '先确认介质、压力、温度、口径。',
            'body_markdown' => '先确认介质、压力、温度、口径。',
            'body_html' => '<p>先确认介质、压力、温度、口径。</p>',
            'tags' => ['橡胶软接头', '采购'],
        ]);

        $this->addToAssertionCount(1); // 不抛异常即通过
    }

    public function test_variant_with_mobile_phone_is_rejected(): void
    {
        $guard = new SelfMediaFactConstraintGuard();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('手机号');

        $guard->assertNoContactInfo([
            'title' => '厂家怎么选？',
            'body_plain' => '公开销售电话为15515958518（微信同号）。',
        ]);
    }

    public function test_variant_with_email_is_rejected(): void
    {
        $guard = new SelfMediaFactConstraintGuard();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('邮箱');

        $guard->assertNoContactInfo([
            'title' => '厂家怎么选？',
            'body_plain' => '联系邮箱 920068264@qq.com。',
        ]);
    }

    public function test_variant_with_url_or_domain_is_rejected(): void
    {
        $guard = new SelfMediaFactConstraintGuard();

        $this->expectException(\DomainException::class);

        $guard->assertNoContactInfo([
            'title' => '厂家怎么选？',
            'body_plain' => '官网 www.hengjiashebei.com 可查看。',
        ]);
    }
}
