<?php

namespace Database\Seeders;

use App\Enums\PurchaseOrderSource;
use App\Enums\PurchaseOrderStatus;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Support\DueDate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * DEMO DATA ONLY. Refuses to run in production.
 * Run it after SupplierSeeder, CatalogSeeder and DummyUserSeeder.
 * Skips itself when purchase orders already exist (use migrate:fresh --seed).
 *
 * 60 POs shaped like the sample sheet, over all 8 statuses, with due dates in
 * the past and the future so overdue and past-grace cases can be tested later.
 * Status and closed_at are written directly because this is seed data; real
 * changes go through StatusTransition::apply().
 *
 * Due-date bands are approximate: with term_counting = "working" the due date
 * can land a day or two before the band's target.
 *   due_in_10_45 / due_in_1_3 ... days from today
 *   due_today
 *   late_1_3 / late_4_7 / late_12_60 ... days past due (12+ is past any grace period of 3 to 7)
 *   none ... no due date (a draft not emailed yet, or a donation/contract)
 */
class PurchaseOrderSeeder extends Seeder
{
    /** status => [due-date band => how many POs]. 60 in total. */
    private const PLAN = [
        'draft' => ['none' => 4, 'due_in_10_45' => 2],
        'uploaded' => ['due_in_10_45' => 3, 'due_in_1_3' => 2, 'late_1_3' => 1, 'late_12_60' => 2],
        'escalated' => ['due_in_10_45' => 2, 'due_in_1_3' => 1, 'late_12_60' => 1],
        'verified' => [
            'due_in_10_45' => 7, 'due_in_1_3' => 4, 'due_today' => 2,
            'late_1_3' => 3, 'late_4_7' => 3, 'late_12_60' => 6, 'none' => 3,
        ],
        'for_termination_approval' => ['late_12_60' => 4],
        'for_termination' => ['late_12_60' => 3],
        'cancelled' => ['due_in_10_45' => 1, 'late_1_3' => 1, 'late_12_60' => 2],
        'terminated' => ['late_12_60' => 3],
    ];

    /** Donation and contract style numbers (no PO date, emailed date or term in the sheet). */
    private const SPECIAL_NUMBERS = ['DRF NO. 2026-11-901', 'DRF NO. 2026-11-902', 'CONTRACT 26-001'];

    /** BizBox lines that are not in our catalog: description => [category name, unit name, unit price]. */
    private const BIZBOX_LINES = [
        'Activated Partial Prothrombin Time' => ['Laboratory Supplies', 'Box', 260.00],
        'Fibrinogen' => ['Laboratory Supplies', 'Box', 260.00],
        'Prothrombin Time w/ INR' => ['Laboratory Supplies', 'Box', 280.00],
        'Battery' => ['Medical and Laboratory Equipment', 'Unit', 55000.00],
        'T-shirt with Collar' => ['Linen and Textiles', 'Piece', 488.00],
        'High Back Chair' => ['Hospital Furniture', 'Unit', 5545.00],
    ];

    private const EXTENSION_REASONS = [
        'Supplier asked for more time: stock arriving late.',
        'Shipment delayed at the port.',
        'Manufacturer backorder, supplier sent a letter.',
        'Supplier asked for more time: partial lot not ready.',
    ];

    /** @var Collection<int, Supplier> */
    private Collection $suppliers;

    /** @var Collection<int, Item> */
    private Collection $items;

    /** @var Collection<string, int> */
    private Collection $categoryIds;

    /** @var Collection<string, int> */
    private Collection $unitIds;

    private ?User $reviewer = null;

    /** @var list<string> */
    private array $specialNumbers = self::SPECIAL_NUMBERS;

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('Fake purchase orders cannot be seeded in production.');

            return;
        }

        if (PurchaseOrder::query()->exists()) {
            $this->command?->info('Purchase orders already exist; PurchaseOrderSeeder skipped.');

            return;
        }

        $this->suppliers = Supplier::query()->get();
        $this->items = Item::query()->with('category')->where('is_active', true)->get();

        if ($this->suppliers->isEmpty() || $this->items->count() < 4) {
            $this->command?->warn('Run SupplierSeeder and CatalogSeeder first.');

            return;
        }

        $this->categoryIds = ItemCategory::query()->pluck('id', 'name');
        $this->unitIds = Unit::query()->pluck('id', 'name');
        $this->reviewer = User::query()->where('employee_no', 'DUMMY-0003')->first() ?? User::query()->first();

        $n = 0;
        foreach (self::PLAN as $status => $bands) {
            foreach ($bands as $band => $count) {
                for ($i = 0; $i < $count; $i++) {
                    $this->createPurchaseOrder(++$n, PurchaseOrderStatus::from($status), $band);
                }
            }
        }

        $this->command?->info("Fake purchase orders seeded: {$n}");
    }

    private function createPurchaseOrder(int $n, PurchaseOrderStatus $status, string $band): void
    {
        $isSpecial = $band === 'none' && $status !== PurchaseOrderStatus::Draft;
        $isBizbox = $n % 10 === 3;
        $term = $isSpecial ? 0 : fake()->randomElement([15, 15, 15, 15, 15, 15, 7, 30, 45, 60, 90]);

        // Some open POs got an approved extension; the band is the due date AFTER it.
        $withExtension = $band !== 'none'
            && $n % 4 === 0
            && $this->reviewer !== null
            && in_array($status, [PurchaseOrderStatus::Uploaded, PurchaseOrderStatus::Verified], true);
        $extraDays = $withExtension ? (int) ceil($term / 2) : 0; // the sheet extends by half the term

        $poDate = null;
        $emailed = null;
        $target = $this->targetDue($band);

        if ($target !== null) {
            $emailed = $this->emailedFor($target, $term, $extraDays);
            $poDate = $emailed->copy()->subDays(fake()->numberBetween(0, 5));
        } elseif (! $isSpecial) {
            $poDate = Carbon::today()->subDays(fake()->numberBetween(1, 10)); // draft, not emailed yet
        }

        $baseDue = DueDate::fromTerm($emailed, $term);
        $due = ($baseDue !== null && $extraDays > 0) ? DueDate::extend($baseDue, $extraDays) : $baseDue;

        $poNumber = $isSpecial
            ? array_shift($this->specialNumbers)
            : $poDate->format('y-m').'-'.sprintf('%04d', 900 + $n);

        $po = PurchaseOrder::factory()->create([
            'po_number' => $poNumber,
            'supplier_id' => $this->suppliers->random()->id,
            'po_date' => $poDate,
            'emailed_date' => $emailed,
            'delivery_term_days' => $term,
            'due_date' => $due,
            'source' => $isBizbox ? PurchaseOrderSource::BizBox : PurchaseOrderSource::Manual,
            'external_id' => $isBizbox ? sprintf('BB-%d-%05d', Carbon::today()->year, 10000 + $n) : null,
            'status' => $status,
            // Nothing is delivered yet, so a cancelled or terminated PO has nothing to pay and is closed.
            'closed_at' => in_array($status, [PurchaseOrderStatus::Cancelled, PurchaseOrderStatus::Terminated], true)
                ? now()->subDays(fake()->numberBetween(0, 10))
                : null,
        ]);

        $this->createLines($po, $isBizbox);

        // Schedule Term: one to three scheduled dates inside the original term, on every other PO.
        if ($emailed !== null && $term > 0 && $n % 2 === 0) {
            $dates = min([1, 2, 3][$n % 3], $term);

            for ($j = 1; $j <= $dates; $j++) {
                $po->schedules()->create([
                    'scheduled_date' => $emailed->copy()->addDays((int) round($term * $j / $dates)),
                ]);
            }
        }

        if ($withExtension) {
            $po->extensions()->create([
                'extra_days' => $extraDays,
                'reason' => fake()->randomElement(self::EXTENSION_REASONS),
                'recorded_by' => $this->reviewer->id,
                'approved_by' => $this->reviewer->id,
                'approved_at' => $baseDue->copy()->subDay()->setTime(10, 0)->min(now()),
                'old_due_date' => $baseDue,
                'new_due_date' => $due,
            ]);
        }
    }

    /** One to four real catalog items; a BizBox PO also gets one line that is not in the catalog. */
    private function createLines(PurchaseOrder $po, bool $isBizbox): void
    {
        foreach ($this->items->random(fake()->numberBetween(1, 4)) as $item) {
            $equipment = $item->category->is_equipment;

            $po->items()->create([
                'item_id' => $item->id,
                'description' => $item->name,
                'category_id' => $item->category_id,
                'unit_id' => $item->unit_id,
                'unit_price' => $equipment ? fake()->numberBetween(5, 400) * 1000 : fake()->randomFloat(2, 15, 2500),
                'quantity_ordered' => $equipment ? fake()->numberBetween(1, 5) : fake()->numberBetween(10, 500),
            ]);
        }

        if ($isBizbox) {
            $description = array_rand(self::BIZBOX_LINES);
            [$categoryName, $unitName, $price] = self::BIZBOX_LINES[$description];

            $po->items()->create([
                'item_id' => null,
                'description' => $description,
                'category_id' => $this->categoryIds->get($categoryName) ?? $this->categoryIds->first(),
                'unit_id' => $this->unitIds->get($unitName) ?? $this->unitIds->first(),
                'unit_price' => $price,
                'quantity_ordered' => fake()->numberBetween(1, 200),
            ]);
        }
    }

    private function targetDue(string $band): ?Carbon
    {
        $today = Carbon::today();

        return match ($band) {
            'due_in_10_45' => $today->copy()->addDays(fake()->numberBetween(10, 45)),
            'due_in_1_3' => $today->copy()->addDays(fake()->numberBetween(1, 3)),
            'due_today' => $today,
            'late_1_3' => $today->copy()->subDays(fake()->numberBetween(1, 3)),
            'late_4_7' => $today->copy()->subDays(fake()->numberBetween(4, 7)),
            'late_12_60' => $today->copy()->subDays(fake()->numberBetween(12, 60)),
            default => null,
        };
    }

    /**
     * An emailed date whose due date (after any extension) lands on the target,
     * or a day or two before it when working-day counting skips weekends.
     */
    private function emailedFor(Carbon $target, int $term, int $extraDays): Carbon
    {
        $emailed = $target->copy()->subDays($term + $extraDays);

        while ($this->finalDue($emailed, $term, $extraDays)->gt($target)) {
            $emailed->subDay();
        }

        return $emailed;
    }

    private function finalDue(Carbon $emailed, int $term, int $extraDays): Carbon
    {
        $due = DueDate::fromTerm($emailed, $term);

        return $extraDays > 0 ? DueDate::extend($due, $extraDays) : $due;
    }
}