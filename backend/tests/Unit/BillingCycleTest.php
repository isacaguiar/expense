<?php

namespace Tests\Unit;

use App\Support\BillingCycle;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class BillingCycleTest extends TestCase
{
    public function test_null_closing_day_current_month_is_open(): void
    {
        $cycle = BillingCycle::cycleFor(null, Carbon::parse('2026-01-15'));

        $this->assertTrue($cycle['start']->isSameDay(Carbon::parse('2026-01-01')));
        $this->assertTrue($cycle['end']->isSameDay(Carbon::parse('2026-01-31')));
        $this->assertSame('open', $cycle['status']);
    }

    public function test_null_closing_day_previous_month_is_closed(): void
    {
        $cycle = BillingCycle::cycleFor(null, Carbon::parse('2026-01-15'), 1);

        $this->assertTrue($cycle['start']->isSameDay(Carbon::parse('2025-12-01')));
        $this->assertTrue($cycle['end']->isSameDay(Carbon::parse('2025-12-31')));
        $this->assertSame('closed', $cycle['status']);
    }

    public function test_null_closing_day_next_month_is_future(): void
    {
        $cycle = BillingCycle::cycleFor(null, Carbon::parse('2026-01-15'), -1);

        $this->assertTrue($cycle['start']->isSameDay(Carbon::parse('2026-02-01')));
        $this->assertTrue($cycle['end']->isSameDay(Carbon::parse('2026-02-28')));
        $this->assertSame('future', $cycle['status']);
    }

    public function test_custom_closing_day_current_cycle_spans_previous_boundary_to_this_months_closing_day(): void
    {
        $cycle = BillingCycle::cycleFor(10, Carbon::parse('2026-01-05'));

        $this->assertTrue($cycle['start']->isSameDay(Carbon::parse('2025-12-11')));
        $this->assertTrue($cycle['end']->isSameDay(Carbon::parse('2026-01-10')));
        $this->assertSame('open', $cycle['status']);
    }

    public function test_cycle_not_closed_on_its_own_closing_day(): void
    {
        $cycle = BillingCycle::cycleFor(10, Carbon::parse('2026-01-10'));

        $this->assertTrue($cycle['start']->isSameDay(Carbon::parse('2025-12-11')));
        $this->assertTrue($cycle['end']->isSameDay(Carbon::parse('2026-01-10')));
        $this->assertSame('open', $cycle['status']);
    }

    public function test_cycle_closed_the_day_after_closing_day(): void
    {
        $current = BillingCycle::cycleFor(10, Carbon::parse('2026-01-11'));
        $previous = BillingCycle::cycleFor(10, Carbon::parse('2026-01-11'), 1);

        $this->assertTrue($current['start']->isSameDay(Carbon::parse('2026-01-11')));
        $this->assertTrue($current['end']->isSameDay(Carbon::parse('2026-02-10')));
        $this->assertSame('open', $current['status']);

        $this->assertTrue($previous['start']->isSameDay(Carbon::parse('2025-12-11')));
        $this->assertTrue($previous['end']->isSameDay(Carbon::parse('2026-01-10')));
        $this->assertSame('closed', $previous['status']);
    }

    public function test_closing_day_clamps_to_last_day_of_shorter_month(): void
    {
        $cycle = BillingCycle::cycleFor(31, Carbon::parse('2026-03-15'), 1);

        $this->assertTrue($cycle['end']->isSameDay(Carbon::parse('2026-02-28')));
        $this->assertSame('closed', $cycle['status']);
    }

    public function test_closing_day_clamps_to_leap_february(): void
    {
        $cycle = BillingCycle::cycleFor(31, Carbon::parse('2028-03-15'), 1);

        $this->assertTrue($cycle['end']->isSameDay(Carbon::parse('2028-02-29')));
        $this->assertSame('closed', $cycle['status']);
    }

    public function test_cycles_ago_navigates_further_into_the_past(): void
    {
        $previous = BillingCycle::cycleFor(10, Carbon::parse('2026-01-15'), 1);
        $twoAgo = BillingCycle::cycleFor(10, Carbon::parse('2026-01-15'), 2);

        $this->assertTrue($previous['end']->isSameDay(Carbon::parse('2026-01-10')));
        $this->assertTrue($twoAgo['end']->isSameDay(Carbon::parse('2025-12-10')));
        $this->assertTrue($twoAgo['start']->isSameDay(Carbon::parse('2025-11-11')));
        $this->assertSame('closed', $previous['status']);
        $this->assertSame('closed', $twoAgo['status']);
    }

    public function test_status_for_computes_status_of_the_cycle_containing_an_arbitrary_date(): void
    {
        $now = Carbon::parse('2026-08-21');

        $this->assertSame('closed', BillingCycle::statusFor(null, Carbon::parse('2026-07-05'), $now));
        $this->assertSame('open', BillingCycle::statusFor(null, Carbon::parse('2026-08-05'), $now));
        $this->assertSame('future', BillingCycle::statusFor(null, Carbon::parse('2026-09-05'), $now));
    }
}
