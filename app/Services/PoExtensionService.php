<?php

namespace App\Services;

use App\Contracts\PurchaseOrderRepositoryInterface;
use App\Events\PoExtensionRecorded;
use App\Models\PoExtension;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\Audit;
use App\Support\DueDate;
use App\Support\ExtensionRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PoExtensionService
{
    public function __construct(
        private readonly PurchaseOrderRepositoryInterface $orders,
    ) {}

    /**
     * Records and approves an extension in one step (no pending state), so
     * recorded_by and approved_by are the same person. Locks the PO row, stores
     * old and new due date, recalculates the PO due date, writes the audit entry,
     * all in one transaction. The status is never touched.
     *
     * @param  array<string, mixed>  $data  validated input (extra_days, reason)
     *
     * @throws ValidationException when the PO may not be extended (error key "form")
     */
    public function recordExtension(PurchaseOrder $purchaseOrder, User $actor, array $data): PoExtension
    {
        $extension = DB::transaction(function () use ($purchaseOrder, $actor, $data) {
            $po = $this->orders->findLocked($purchaseOrder->id);

            if (($reason = ExtensionRule::blockReason($po)) !== null) {
                throw ValidationException::withMessages(['form' => $reason]);
            }

            $days = (int) $data['extra_days'];
            $why = trim((string) $data['reason']);
            $oldDue = $po->due_date;

            $extension = $po->extensions()->create([
                'extra_days' => $days,
                'reason' => $why,
                'recorded_by' => $actor->id,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'old_due_date' => $oldDue,
                'new_due_date' => DueDate::extend($oldDue, $days),
            ]);

            // The PO due date always comes from recalculateDueDate() (term plus every
            // approved extension, the new one included). The history row copies it,
            // so the two can never disagree.
            $po->recalculateDueDate();
            $po->save();

            $extension->new_due_date = $po->due_date;
            $extension->save();

            $word = $days === 1 ? 'day' : 'days';

            Audit::log(
                'extension_recorded',
                $po,
                "Recorded a {$days}-{$word} extension on PO {$po->auditLabel()}",
                old: ['due_date' => $oldDue->toDateString()],
                new: [
                    'due_date' => $po->due_date->toDateString(),
                    'extension' => "+{$days} {$word}: {$why}",
                ],
                log: 'po',
            );

            return $extension;
        });

        // After the commit, so a listener never sees a rolled-back extension.
        PoExtensionRecorded::dispatch($extension);

        return $extension;
    }
}