<?php

namespace Tests\Unit;

use App\Enums\DeliveryProgress;
use App\Enums\DeliveryStatus;
use App\Enums\DeliveryTiming;
use App\Enums\DvStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\ReplacementStatus;
use App\Support\StatusTransition;
use PHPUnit\Framework\TestCase;

/**
 * Pure PHP checks of the status rules. No database, no app boot.
 * StatusTransition::apply() (database + audit) is tested in 3.1, when the
 * first model with a status column exists.
 */
class StatusModelTest extends TestCase
{
    /** @return array<int, class-string<\App\Enums\Transitionable&\BackedEnum>> */
    private static function storedEnums(): array
    {
        return [
            PurchaseOrderStatus::class,
            DeliveryStatus::class,
            DvStatus::class,
            ReplacementStatus::class,
        ];
    }

    public function test_every_status_has_a_unique_label(): void
    {
        foreach ([...self::storedEnums(), DeliveryProgress::class, DeliveryTiming::class] as $enum) {
            $labels = array_map(fn ($case) => $case->label(), $enum::cases());

            $this->assertSame($labels, array_unique($labels), "{$enum}: duplicate or empty label.");
            $this->assertNotContains('', $labels, "{$enum}: empty label.");
        }
    }

    public function test_transitions_stay_inside_the_same_enum_and_never_loop(): void
    {
        foreach (self::storedEnums() as $enum) {
            foreach ($enum::cases() as $case) {
                foreach ($case->allowedNext() as $next) {
                    $this->assertInstanceOf($enum, $next);
                    $this->assertNotSame($case, $next, "{$enum}::{$case->name} lists itself.");
                }
            }
        }
    }

    public function test_every_status_can_be_reached_from_the_first_one(): void
    {
        foreach (self::storedEnums() as $enum) {
            $cases = $enum::cases();
            $seen = [$cases[0]];
            $queue = [$cases[0]];

            while ($queue !== []) {
                $current = array_shift($queue);

                foreach ($current->allowedNext() as $next) {
                    if (! in_array($next, $seen, true)) {
                        $seen[] = $next;
                        $queue[] = $next;
                    }
                }
            }

            $this->assertCount(count($cases), $seen, "{$enum}: some status can never be reached.");
        }
    }

    public function test_final_statuses(): void
    {
        $this->assertTrue(PurchaseOrderStatus::Cancelled->isFinal());
        $this->assertTrue(PurchaseOrderStatus::Terminated->isFinal());
        $this->assertTrue(DeliveryStatus::Voided->isFinal());
        $this->assertTrue(DvStatus::PaymentDisbursed->isFinal());
        $this->assertTrue(ReplacementStatus::Replaced->isFinal());

        $this->assertFalse(PurchaseOrderStatus::Verified->isFinal());
        $this->assertFalse(DvStatus::Returned->isFinal());
    }

    public function test_can_allows_valid_and_rejects_invalid_changes(): void
    {
        $this->assertTrue(StatusTransition::can(PurchaseOrderStatus::Uploaded, PurchaseOrderStatus::Verified));
        $this->assertTrue(StatusTransition::can(PurchaseOrderStatus::Verified, PurchaseOrderStatus::ForTerminationApproval));
        $this->assertTrue(StatusTransition::can(PurchaseOrderStatus::ForTerminationApproval, PurchaseOrderStatus::ForTermination));
        $this->assertTrue(StatusTransition::can(PurchaseOrderStatus::ForTerminationApproval, PurchaseOrderStatus::Verified));
        $this->assertTrue(StatusTransition::can(PurchaseOrderStatus::ForTermination, PurchaseOrderStatus::Terminated));

        // skipping a step, going back from a final status, and mixing enums
        $this->assertFalse(StatusTransition::can(PurchaseOrderStatus::Draft, PurchaseOrderStatus::Verified));
        $this->assertFalse(StatusTransition::can(PurchaseOrderStatus::Uploaded, PurchaseOrderStatus::ForTermination));
        $this->assertFalse(StatusTransition::can(PurchaseOrderStatus::Verified, PurchaseOrderStatus::ForTermination)); // must be approved first
        $this->assertFalse(StatusTransition::can(PurchaseOrderStatus::ForTermination, PurchaseOrderStatus::Verified));
        $this->assertFalse(StatusTransition::can(PurchaseOrderStatus::Cancelled, PurchaseOrderStatus::Verified));
        $this->assertFalse(StatusTransition::can(PurchaseOrderStatus::Verified, PurchaseOrderStatus::Verified));
        $this->assertFalse(StatusTransition::can(DvStatus::Draft, PurchaseOrderStatus::Verified));

        $this->assertFalse(StatusTransition::can(DvStatus::ForFinanceProcessing, DvStatus::PaymentDisbursed));
        $this->assertTrue(StatusTransition::can(DvStatus::Returned, DvStatus::Draft));
    }

    public function test_reason_rules(): void
    {
        $this->assertTrue(PurchaseOrderStatus::Uploaded->requiresReasonFor(PurchaseOrderStatus::Escalated));
        $this->assertTrue(PurchaseOrderStatus::Verified->requiresReasonFor(PurchaseOrderStatus::ForTerminationApproval));
        $this->assertTrue(PurchaseOrderStatus::ForTerminationApproval->requiresReasonFor(PurchaseOrderStatus::Verified)); // disapproval
        $this->assertFalse(PurchaseOrderStatus::ForTerminationApproval->requiresReasonFor(PurchaseOrderStatus::ForTermination));
        $this->assertFalse(PurchaseOrderStatus::Uploaded->requiresReasonFor(PurchaseOrderStatus::Verified));

        $this->assertTrue(DvStatus::ReceivedByFinance->requiresReasonFor(DvStatus::Returned));
        $this->assertFalse(DvStatus::ReceivedByFinance->requiresReasonFor(DvStatus::PaymentDisbursed));

        $this->assertTrue(DeliveryStatus::Complete->requiresReasonFor(DeliveryStatus::Voided));
        $this->assertTrue(DeliveryStatus::Complete->requiresReasonFor(DeliveryStatus::ForUploading));
        $this->assertFalse(DeliveryStatus::ForUploading->requiresReasonFor(DeliveryStatus::Complete));
    }

    public function test_delivery_progress_is_computed_from_totals(): void
    {
        $this->assertSame(DeliveryProgress::NotDelivered, DeliveryProgress::fromTotals(1000, 0));
        $this->assertSame(DeliveryProgress::Partial, DeliveryProgress::fromTotals(1000, 500));
        $this->assertSame(DeliveryProgress::Full, DeliveryProgress::fromTotals(1000, 1000));
        $this->assertSame(DeliveryProgress::Full, DeliveryProgress::fromTotals(1000, 1100));
        $this->assertSame(DeliveryProgress::NotDelivered, DeliveryProgress::fromTotals(0, 0));
    }

    public function test_delivery_timing_uses_the_grace_period(): void
    {
        $this->assertSame(DeliveryTiming::OnTime, DeliveryTiming::fromDaysLate(0, 5));
        $this->assertSame(DeliveryTiming::OnTime, DeliveryTiming::fromDaysLate(-3, 5));
        $this->assertSame(DeliveryTiming::Overdue, DeliveryTiming::fromDaysLate(1, 5));
        $this->assertSame(DeliveryTiming::Overdue, DeliveryTiming::fromDaysLate(5, 5));
        $this->assertSame(DeliveryTiming::PastGrace, DeliveryTiming::fromDaysLate(6, 5));

        // grace period not set: never "past grace"
        $this->assertSame(DeliveryTiming::Overdue, DeliveryTiming::fromDaysLate(90, null));
    }
}