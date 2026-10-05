<?php

namespace Tests\Unit\Support;

use App\Support\PanelFormat;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PanelFormatTest extends TestCase
{
    public function test_money_always_has_three_decimals_and_groups_thousands(): void
    {
        $this->assertSame('0.000', PanelFormat::amount(0));
        $this->assertSame('0.001', PanelFormat::amount(1));
        $this->assertSame('12.500', PanelFormat::amount(12_500));
        $this->assertSame('1,234.567', PanelFormat::amount(1_234_567));
    }

    public function test_money_carries_the_symbol_in_the_active_language(): void
    {
        app()->setLocale('en');
        $this->assertSame('12.500 KWD', PanelFormat::money(12_500));

        app()->setLocale('ar');
        $this->assertSame('12.500 د.ك', PanelFormat::money(12_500));
    }

    public function test_moments_are_shown_in_the_shops_own_time_not_utc(): void
    {
        app()->setLocale('en');
        $at = Carbon::parse('2026-10-05 21:30:00', 'UTC');

        $this->assertSame('6 Oct 2026، 12:30 AM', PanelFormat::dateTime($at));
        $this->assertSame('6 Oct 2026', PanelFormat::date($at));
        $this->assertSame('12:30 AM', PanelFormat::time($at));
    }

    public function test_moments_read_in_arabic_when_the_panel_is_arabic(): void
    {
        app()->setLocale('ar');

        $this->assertSame('6 أكتوبر 2026', PanelFormat::date(Carbon::parse('2026-10-05 21:30:00', 'UTC')));
    }

    public function test_formatting_never_changes_the_moment_it_is_given(): void
    {
        $at = Carbon::parse('2026-10-05 21:30:00', 'UTC');

        PanelFormat::dateTime($at);
        PanelFormat::ago($at);
        PanelFormat::inputDateTime($at);

        $this->assertSame('UTC', $at->getTimezone()->getName());
        $this->assertSame('2026-10-05 21:30:00', $at->format('Y-m-d H:i:s'));
    }

    public function test_a_missing_moment_is_a_dash_or_nothing_never_an_error(): void
    {
        $this->assertSame('—', PanelFormat::dateTime(null));
        $this->assertSame('—', PanelFormat::date(null));
        $this->assertSame('—', PanelFormat::time(null));
        $this->assertSame('—', PanelFormat::ago(null));
        $this->assertSame('', PanelFormat::inputDateTime(null));
    }

    public function test_a_form_field_gets_the_shops_clock_in_the_shape_it_expects(): void
    {
        $this->assertSame('2026-10-06T00:30', PanelFormat::inputDateTime(Carbon::parse('2026-10-05 21:30:00', 'UTC')));
    }

    public function test_the_timezone_can_be_changed_from_configuration(): void
    {
        config(['store.timezone' => 'UTC']);

        $this->assertSame('2026-10-05T21:30', PanelFormat::inputDateTime(Carbon::parse('2026-10-05 21:30:00', 'UTC')));
    }

    public function test_a_link_to_the_store_follows_the_panels_language(): void
    {
        app()->setLocale('ar');
        $this->assertSame(url('/ar-KW/products/oud'), PanelFormat::storeUrl('products/oud'));
        $this->assertSame(url('/ar-KW'), PanelFormat::storeUrl());

        app()->setLocale('en');
        $this->assertSame(url('/en-KW/products/oud'), PanelFormat::storeUrl('/products/oud'));
    }
}
