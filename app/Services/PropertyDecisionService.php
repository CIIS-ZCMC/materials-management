<?php

namespace App\Services;

use App\Enums\PropertyDecisionStatus;
use App\Enums\PropertyDocumentStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\DeliveryItem;
use App\Models\PropertyDecision;
use App\Models\PropertyDocument;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use App\Support\PropertyDocumentRule;
use App\Support\StatusTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Equipment decisions (step 6.2): "is this delivered line equipment?" plus what the rule says about it.
 *
 *   decide()        records the decision and snapshots the rule. Locks the delivered-item row first, so two people
 *                   deciding the same line at once cannot both succeed ("one ACTIVE decision per line").
 *   voidDecision()  a wrong decision is voided with a reason and decided again, never edited. Refused while it still
 *                   has issued documents.
 *
 * Voids are status changes, so they go through StatusTransition (audit event "status_changed", log "property").
 */
class PropertyDecisionService
{
    public function decide(DeliveryItem $item, bool $isEquipment, ?string $overrideReason, ?string $remarks, User $actor): PropertyDecision
    {
        $overrideReason = $this->clean($overrideReason);
        $remarks = $this->clean($remarks);

        return DB::transaction(function () use ($item, $isEquipment, $overrideReason, $remarks, $actor): PropertyDecision {
            $row = DeliveryItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            $row->load(['delivery', 'poItem.category', 'poItem.unit']);

            $hasActive = PropertyDecision::query()
                ->where('delivery_item_id', $row->id)
                ->where('status', PropertyDecisionStatus::Active->value)
                ->exists();

            if ($reason = PropertyDocumentRule::decideBlockReason($row, $hasActive)) {
                throw ValidationException::withMessages(['form' => $reason]);
            }

            $default = (bool) $row->poItem->category?->is_equipment;
            $errors = [];

            if ($isEquipment !== $default) {
                if ($error = PropertyDocumentRule::reasonError($overrideReason)) {
                    $errors['override_reason'] = 'The category says '.($default ? 'equipment' : 'not equipment').'. '.$error;
                }
            } else {
                $overrideReason = null;
            }

            $cents = Money::cents($row->poItem->unit_price);
            $band = PropertyDocumentRule::band($isEquipment, $cents);

            if ($band->requiresRemarks() && ($error = PropertyDocumentRule::reasonError($remarks))) {
                $errors['remarks'] = 'Remarks are required below the ICS minimum. '.str_replace('reason', 'remark', $error);
            }

            if ($remarks !== null && mb_strlen($remarks) > 500) {
                $errors['remarks'] = 'Remarks can have at most 500 characters.';
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $decision = new PropertyDecision;
            $decision->forceFill([
                'delivery_item_id' => $row->id,
                'is_equipment' => $isEquipment,
                'category_default' => $default,
                'override_reason' => $overrideReason,
                'unit_price_cents' => $cents,
                'band' => $band,
                'par_threshold_cents' => PropertyDocumentRule::parThresholdCents(),
                'ics_min_cents' => PropertyDocumentRule::icsMinCents(),
                'rule_provisional' => PropertyDocumentRule::isProvisional(),
                'remarks' => $remarks,
                'status' => PropertyDecisionStatus::Active,
                'decided_by' => $actor->id,
            ])->save();

            Audit::log(
                event: 'property_decided',
                subject: $decision,
                description: 'Equipment decision: '.$row->auditLabel().' is '.($isEquipment ? 'equipment' : 'not equipment').' ('.$band->label().')',
                new: [
                    'equipment' => $isEquipment ? 'yes' : 'no',
                    'category_default' => $default ? 'yes' : 'no',
                    'rule_band' => $band->label(),
                    'unit_price' => Money::php($cents),
                    'remarks' => $remarks,
                ],
                reason: $overrideReason,
                extra: [
                    'delivery_item_id' => $row->id,
                    'delivery_id' => $row->delivery_id,
                    'unit_price_cents' => $cents,
                    'par_threshold_cents' => $decision->par_threshold_cents,
                    'ics_min_cents' => $decision->ics_min_cents,
                    'rule_provisional' => $decision->rule_provisional,
                ],
                causer: $actor,
                log: 'property',
            );

            return $decision;
        });
    }

    public function voidDecision(PropertyDecision $decision, string $reason, User $actor): PropertyDecision
    {
        $reason = trim($reason);

        if ($error = PropertyDocumentRule::reasonError($reason)) {
            throw ValidationException::withMessages(['reason' => $error]);
        }

        return DB::transaction(function () use ($decision, $reason, $actor): PropertyDecision {
            $row = PropertyDecision::query()->whereKey($decision->getKey())->lockForUpdate()->firstOrFail();

            $issued = PropertyDocument::query()
                ->where('property_decision_id', $row->id)
                ->where('status', PropertyDocumentStatus::Issued->value)
                ->count();

            if ($blocked = PropertyDocumentRule::voidDecisionBlockReason($row, $issued)) {
                throw ValidationException::withMessages(['form' => $blocked]);
            }

            try {
                StatusTransition::apply(
                    $row,
                    'status',
                    PropertyDecisionStatus::Voided,
                    reason: $reason,
                    extra: ['delivery_item_id' => $row->delivery_item_id, 'rule_band' => $row->band->label()],
                    log: 'property',
                );
            } catch (InvalidStatusTransition $e) {
                throw ValidationException::withMessages(['form' => $e->getMessage()]);
            }

            $row->forceFill([
                'void_reason' => $reason,
                'voided_by' => $actor->id,
                'voided_at' => now(),
            ])->save();

            return $row;
        });
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
