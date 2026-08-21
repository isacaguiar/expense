<?php

namespace Tests\Unit;

use App\Support\BillingCycle;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class BillingCycleTest extends TestCase
{
    public function test_null_closing_day_returns_previous_full_calendar_month(): void
    {
        $cycle = BillingCycle::closedCycle(null, Carbon::parse('2026-02-01'));

        $this->assertTrue($cycle['start']->isSameDay(Carbon::parse('2026-01-01')));
        $this->assertTrue($cycle['end']->isSameDay(Carbon::parse('2026-01-31')));
    }

    public function test_null_closing_day_is_stable_for_any_day_within_current_month(): void
    {
        $cycle = BillingCycle::closedCycle(null, Carbon::parse('2026-01-15'));

        $this->assertTrue($cycle['start']->isSameDay(Carbon::parse('2025-12-01')));
        $this->assertTrue($cycle['end']->isSameDay(Carbon::parse('2025-12-31')));
    }

    public function test_custom_closing_day_returns_cycle_from_day_after_previous_boundary(): void
    {
        $cycle = BillingCycle::closedCycle(10, Carbon::parse('2026-01-15'));

        $this->assertTrue($cycle['start']->isSameDay(Carbon::parse('2025-12-11')));
        $this->assertTrue($cycle['end']->isSameDay(Carbon::parse('2026-01-10')));
    }

    public function test_cycle_not_closed_on_its_own_closing_day(): void
    {
        $cycle = BillingCycle::closedCycle(10, Carbon::parse('2026-01-10'));

        $this->assertTrue($cycle['start']->isSameDay(Carbon::parse('2025-11-11')));
        $this->assertTrue($cycle['end']->isSameDay(Carbon::parse('2025-12-10')));
    }

    public function test_cycle_closed_the_day_after_closing_day(): void
    {
        $cycle = BillingCycle::closedCycle(10, Carbon::parse('2026-01-11'));

        $this->assertTrue($cycle['start']->isSameDay(Carbon::parse('2025-12-11')));
        $this->assertTrue($cycle['end']->isSameDay(Carbon::parse('2026-01-10')));
    }

    public function test_closing_day_clamps_to_last_day_of_shorter_month(): void
    {
        $cycle = BillingCycle::closedCycle(31, Carbon::parse('2026-03-15'));

        $this->assertTrue($cycle['end']->isSameDay(Carbon::parse('2026-02-28')));
    }

    public function test_closing_day_clamps_to_leap_february(): void
    {
        $cycle = BillingCycle::closedCycle(31, Carbon::parse('2028-03-15'));

        $this->assertTrue($cycle['end']->isSameDay(Carbon::parse('2028-02-29')));
    }

    public function test_cycles_ago_returns_the_previous_closed_cycle(): void
    {
        $mostRecent = BillingCycle::closedCycle(10, Carbon::parse('2026-01-15'), 0);
        $previous = BillingCycle::closedCycle(10, Carbon::parse('2026-01-15'), 1);

        $this->assertTrue($mostRecent['end']->isSameDay(Carbon::parse('2026-01-10')));
        $this->assertTrue($previous['end']->isSameDay(Carbon::parse('2025-12-10')));
        $this->assertTrue($previous['start']->isSameDay(Carbon::parse('2025-11-11')));
    }
}
