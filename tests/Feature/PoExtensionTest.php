<?php

namespace Tests\Feature;

use App\Events\PoExtensionRecorded;
use App\Models\PoExtension;
use App\Models\PurchaseOrder;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PoExtensionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'mms.delivery.term_counting' => 'calendar',
            'mms.delivery.extension_counting' => 'working',
            'mms.delivery.extension_max_days' => 90,
        ]);
    }

    /** MMS Staff + extension.manage = the PO Reviewer job. */
    private function reviewer(string $name = 'Rey Reviewer'): User
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true]);
        $user->assignRole('MMS Staff');
        $user->givePermissionTo('extension.manage');

        return $user;
    }

    /**
     * A verified PO emailed Monday 2026-11-02 with a 10 calendar-day term:
     * due Thursday 2026-11-12.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function po(array $overrides = []): PurchaseOrder
    {
        return PurchaseOrder::factory()->create(array_merge([
            'status' => 'verified',
            'emailed_date' => '2026-11-02',
            'delivery_term_days' => 10,
            'due_date' => '2026-11-12',
            'closed_at' => null,
        ], $overrides));
    }

    private function url(PurchaseOrder $po): string
    {
        return "/purchase-orders/{$po->id}/extensions";
    }

    public function test_extension_moves_the_due_date_in_working_days_and_skips_the_weekend(): void
    {
        $user = $this->reviewer();
        $po = $this->po();

        // Thu 12 Nov + 3 working days = Fri 13, Mon 16, Tue 17.
        $this->actingAs($user)
            ->post($this->url($po), ['extra_days' => 3, 'reason' => 'Supplier delay'])
            ->assertRedirect(route('purchase-orders.show', $po))
            ->assertSessionHas('success');

        $po->refresh();

        $this->assertSame('2026-11-17', $po->due_date->toDateString());
        $this->assertSame('verified', $po->status->value);

        $extension = PoExtension::query()->where('purchase_order_id', $po->id)->sole();
        $this->assertSame(3, $extension->extra_days);
        $this->assertSame('Supplier delay', $extension->reason);
        $this->assertSame('2026-11-12', $extension->old_due_date->toDateString());
        $this->assertSame('2026-11-17', $extension->new_due_date->toDateString());
        $this->assertSame($user->id, $extension->recorded_by);
        $this->assertSame($user->id, $extension->approved_by);
        $this->assertNotNull($extension->approved_at);
    }

    public function test_two_working_days_from_thursday_land_on_monday(): void
    {
        $po = $this->po();

        $this->actingAs($this->reviewer())->post($this->url($po), ['extra_days' => 2, 'reason' => 'Port delay']);

        $this->assertSame('2026-11-16', $po->refresh()->due_date->toDateString());
    }

    public function test_a_second_extension_stacks_on_the_first(): void
    {
        $user = $this->reviewer();
        $po = $this->po();

        $this->actingAs($user)->post($this->url($po), ['extra_days' => 3, 'reason' => 'First']);
        $this->actingAs($user)->post($this->url($po), ['extra_days' => 5, 'reason' => 'Second']);

        // 17 Nov + 5 working days = Wed 18, Thu 19, Fri 20, Mon 23, Tue 24.
        $this->assertSame('2026-11-24', $po->refresh()->due_date->toDateString());

        $second = PoExtension::query()->where('purchase_order_id', $po->id)->orderByDesc('id')->first();
        $this->assertSame('2026-11-17', $second->old_due_date->toDateString());
        $this->assertSame('2026-11-24', $second->new_due_date->toDateString());
    }

    public function test_history_lists_both_extensions_with_who_and_when(): void
    {
        $po = $this->po();
        $first = $this->reviewer('Rey Reviewer');
        $second = $this->reviewer('Ana Approver');

        $this->actingAs($first)->post($this->url($po), ['extra_days' => 3, 'reason' => 'First']);
        $this->actingAs($second)->post($this->url($po), ['extra_days' => 5, 'reason' => 'Second']);

        $this->actingAs($first)
            ->get("/purchase-orders/{$po->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('purchase-orders/show')
                ->has('purchaseOrder.extensions', 2)
                ->where('purchaseOrder.extensions.0.approved_by', 'Rey Reviewer')
                ->where('purchaseOrder.extensions.1.approved_by', 'Ana Approver')
                ->where('purchaseOrder.extensions.1.new_due_date', '2026-11-24')
                ->whereType('purchaseOrder.extensions.0.approved_at', 'string')
                ->where('purchaseOrder.extension.allowed', true));
    }

    public function test_a_po_without_a_due_date_cannot_be_extended(): void
    {
        $po = $this->po(['emailed_date' => null, 'delivery_term_days' => 0, 'due_date' => null]);

        $this->actingAs($this->reviewer())
            ->post($this->url($po), ['extra_days' => 3, 'reason' => 'Supplier delay'])
            ->assertSessionHasErrors('form');

        $this->assertSame(0, PoExtension::query()->count());
        $this->assertNull($po->refresh()->due_date);
    }

    public function test_a_closed_po_cannot_be_extended(): void
    {
        $po = $this->po(['closed_at' => now()]);

        $this->actingAs($this->reviewer())
            ->post($this->url($po), ['extra_days' => 3, 'reason' => 'Supplier delay'])
            ->assertSessionHasErrors('form');

        $this->assertSame(0, PoExtension::query()->count());
    }

    public function test_only_verified_pos_can_be_extended(): void
    {
        $user = $this->reviewer();

        foreach (['draft', 'uploaded', 'escalated', 'for_termination_approval', 'for_termination', 'cancelled', 'terminated'] as $status) {
            $po = $this->po(['status' => $status, 'po_number' => 'PO-'.$status]);

            $this->actingAs($user)
                ->post($this->url($po), ['extra_days' => 3, 'reason' => 'Supplier delay'])
                ->assertSessionHasErrors('form');

            $this->assertSame('2026-11-12', $po->refresh()->due_date->toDateString(), $status);
        }

        $this->assertSame(0, PoExtension::query()->count());
    }

    public function test_an_overdue_verified_po_can_still_be_extended(): void
    {
        $po = $this->po(['emailed_date' => '2025-01-06', 'due_date' => '2025-01-16']);

        $this->actingAs($this->reviewer())
            ->post($this->url($po), ['extra_days' => 3, 'reason' => 'Late but agreed'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, PoExtension::query()->count());
    }

    public function test_users_without_extension_manage_get_403(): void
    {
        $po = $this->po();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('Admin');

        $procurement = User::factory()->create(['is_active' => true]);
        $procurement->assignRole('Procurement');

        foreach ([$admin, $procurement] as $user) {
            $this->actingAs($user)
                ->post($this->url($po), ['extra_days' => 3, 'reason' => 'Supplier delay'])
                ->assertForbidden();
        }

        $this->assertSame(0, PoExtension::query()->count());
    }

    public function test_the_extension_button_is_only_offered_to_people_with_the_permission(): void
    {
        $po = $this->po();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('Admin');

        $this->actingAs($admin)
            ->get("/purchase-orders/{$po->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('purchaseOrder.extension.allowed', false)
                ->where('purchaseOrder.extension.reason', null));
    }

    public function test_days_and_reason_are_validated(): void
    {
        $user = $this->reviewer();
        $po = $this->po();

        $this->actingAs($user)->post($this->url($po), ['extra_days' => 0, 'reason' => 'x'])->assertSessionHasErrors('extra_days');
        $this->actingAs($user)->post($this->url($po), ['extra_days' => 91, 'reason' => 'x'])->assertSessionHasErrors('extra_days');
        $this->actingAs($user)->post($this->url($po), ['extra_days' => 2.5, 'reason' => 'x'])->assertSessionHasErrors('extra_days');
        $this->actingAs($user)->post($this->url($po), ['extra_days' => 'abc', 'reason' => 'x'])->assertSessionHasErrors('extra_days');
        $this->actingAs($user)->post($this->url($po), ['extra_days' => 3, 'reason' => '   '])->assertSessionHasErrors('reason');

        $this->assertSame(0, PoExtension::query()->count());
        $this->assertSame('2026-11-12', $po->refresh()->due_date->toDateString());
    }

    public function test_an_audit_entry_is_written_and_the_event_is_fired(): void
    {
        Event::fake([PoExtensionRecorded::class]);

        $user = $this->reviewer();
        $po = $this->po();

        $this->actingAs($user)->post($this->url($po), ['extra_days' => 3, 'reason' => 'Supplier delay']);

        $this->assertTrue(
            DB::table('activity_log')
                ->where('log_name', 'po')
                ->where('event', 'extension_recorded')
                ->where('subject_id', $po->id)
                ->where('causer_id', $user->id)
                ->exists(),
        );

        Event::assertDispatched(PoExtensionRecorded::class);
    }
}