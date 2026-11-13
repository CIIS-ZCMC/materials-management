<?php

namespace Tests\Feature;

use App\Support\DueDate;
use Illuminate\Support\Carbon;
use LogicException;
use Tests\TestCase;

class DueDateTest extends TestCase
{
    public function test_calendar_term_counts_from_the_emailed_date(): void
    {
        config(['mms.delivery.term_counting' => 'calendar']);

        // Sample sheet: PO 26-03-0903, emailed 2026-07-21, 60 days.
        $due = DueDate::fromTerm(Carbon::parse('2026-07-21'), 60);

        $this->assertSame('2026-09-19', $due->toDateString());
    }

    public function test_working_term_skips_the_weekend(): void
    {
        config(['mms.delivery.term_counting' => 'working']);

        // Tuesday 2026-07-21 + 5 working days = Tuesday 2026-07-28.
        $this->assertSame('2026-07-28', DueDate::fromTerm(Carbon::parse('2026-07-21'), 5)->toDateString());

        // Friday 2026-07-24 + 1 working day = Monday 2026-07-27.
        $this->assertSame('2026-07-27', DueDate::fromTerm(Carbon::parse('2026-07-24'), 1)->toDateString());
    }

    public function test_no_emailed_date_or_no_term_means_no_due_date(): void
    {
        $this->assertNull(DueDate::fromTerm(null, 15));
        $this->assertNull(DueDate::fromTerm(Carbon::parse('2026-07-21'), 0));
        $this->assertNull(DueDate::fromTerm(Carbon::parse('2026-07-21'), null));
    }

    public function test_extension_uses_its_own_counting_setting(): void
    {
        $due = Carbon::parse('2026-07-22'); // Wednesday

        config(['mms.delivery.extension_counting' => 'calendar']);
        $this->assertSame('2026-07-30', DueDate::extend($due, 8)->toDateString());

        config(['mms.delivery.extension_counting' => 'working']);
        $this->assertSame('2026-08-03', DueDate::extend($due, 8)->toDateString());
    }

    public function test_the_original_date_is_not_changed(): void
    {
        $emailed = Carbon::parse('2026-07-21');

        DueDate::fromTerm($emailed, 15);

        $this->assertSame('2026-07-21', $emailed->toDateString());
    }

    public function test_an_unknown_counting_value_is_an_error(): void
    {
        config(['mms.delivery.term_counting' => 'weekly']);

        $this->expectException(LogicException::class);

        DueDate::fromTerm(Carbon::parse('2026-07-21'), 15);
    }
}