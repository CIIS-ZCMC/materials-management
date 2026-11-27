<?php

namespace App\Services;

use App\Contracts\PropertyRepositoryInterface;
use App\Enums\PropertyBand;
use App\Enums\PropertyDecisionStatus;
use App\Enums\PropertyDocumentStatus;
use App\Enums\PropertyDocumentType;
use App\Models\DeliveryItem;
use App\Models\PropertyDecision;
use App\Models\PropertyDocument;
use App\Models\User;
use App\Support\DisplayTime;
use App\Support\Money;
use App\Support\PropertyDocumentRule;
use App\Support\Quantity;
use Illuminate\Support\Facades\Gate;

/**
 * What the property pages show (step 6.2). Reads only. The state of a line is computed here from its decisions and
 * documents, never stored. The "can" flags use the same Policy as the buttons' routes, so a button is shown only when
 * the click would be allowed; the reason is shown only to people who hold the permission.
 */
class PropertyOverviewService
{
    public const PER_PAGE = 15;

    public function __construct(private readonly PropertyRepositoryInterface $repository) {}

    /** @return array<string, mixed> */
    public function listing(string $tab, string $search): array
    {
        $tab = in_array($tab, PropertyRepositoryInterface::TABS, true) ? $tab : PropertyRepositoryInterface::TAB_PENDING;

        $rows = $tab === PropertyRepositoryInterface::TAB_VOIDED
            ? $this->repository->paginateVoidedDocuments($search, self::PER_PAGE)->through(fn (PropertyDocument $d) => $this->voidedRow($d))
            : $this->repository->paginateItems($tab, $search, self::PER_PAGE)->through(fn (DeliveryItem $i) => $this->itemRow($i));

        $counts = [];

        foreach (PropertyRepositoryInterface::TABS as $name) {
            $counts[$name] = $this->repository->count($name);
        }

        return [
            'tab' => $tab,
            'rows' => $rows,
            'counts' => $counts,
            'filters' => ['search' => $search, 'tab' => $tab],
            'rule' => $this->ruleSummary(),
        ];
    }

    /** @return array<string, mixed> */
    public function sheet(DeliveryItem $item, User $user): array
    {
        $item->load(['delivery.purchaseOrder', 'poItem.unit', 'poItem.category']);

        $decisions = $this->repository->decisionsFor($item->id);
        $active = $decisions->first(fn (PropertyDecision $d) => $d->status === PropertyDecisionStatus::Active);
        $cents = Money::cents($item->poItem->unit_price);
        $default = (bool) $item->poItem->category?->is_equipment;

        $can = [
            'decide' => $this->gate($user, 'decide', [PropertyDecision::class, $item], 'equipment.decide'),
            'void_decision' => $active ? $this->gate($user, 'voidDecision', $active, 'equipment.decide') : $this->none(),
            'issue' => [],
        ];

        foreach (PropertyDocumentType::cases() as $type) {
            $can['issue'][$type->value] = $active
                ? $this->gate($user, 'issue', [$active, $type], $type->permission())
                : $this->none();
        }

        $documents = [];

        foreach ($decisions as $decision) {
            foreach ($decision->documents as $doc) {
                $documents[] = $this->documentRow($doc, $user, $decision->id === $active?->id);
            }
        }

        return [
            'item' => [
                'id' => $item->id,
                'description' => $item->poItem->description,
                'unit' => $item->poItem->unit?->name,
                'quantity' => Quantity::format(Quantity::thousandths($item->quantity)),
                'unit_price' => Money::php($cents),
                'category' => $item->poItem->category?->name,
                'category_is_equipment' => $default,
                'iar_number' => $item->delivery->iar_number,
                'delivery_id' => $item->delivery_id,
                'po_number' => $item->delivery->purchaseOrder->po_number,
                'purchase_order_id' => $item->delivery->purchase_order_id,
                'delivered_on' => $item->delivered_on?->toDateString(),
                'source_problem' => PropertyDocumentRule::sourceBlockReason($item),
            ],
            'preview' => [
                'equipment' => $this->bandInfo(PropertyDocumentRule::band(true, $cents)),
                'not_equipment' => $this->bandInfo(PropertyBand::NotEquipment),
            ],
            'rule' => $this->ruleSummary(),
            'decision' => $active ? $this->decisionRow($active) : null,
            'history' => $decisions
                ->filter(fn (PropertyDecision $d) => $d->status === PropertyDecisionStatus::Voided)
                ->map(fn (PropertyDecision $d) => $this->decisionRow($d))->values()->all(),
            'documents' => $documents,
            'missing' => $active
                ? array_map(
                    fn (PropertyDocumentType $t) => $t->value,
                    PropertyDocumentRule::missingTypes($active, $this->issuedTypes($active)),
                )
                : [],
            'can' => $can,
            'year' => app(PropertyNumberService::class)->currentYear(),
        ];
    }

    // ---- rows ------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function itemRow(DeliveryItem $item): array
    {
        $active = $item->propertyDecisions->first(fn (PropertyDecision $d) => $d->status === PropertyDecisionStatus::Active);
        $issued = $active
            ? $active->documents->filter(fn (PropertyDocument $d) => $d->status === PropertyDocumentStatus::Issued)
            : collect();

        $problem = PropertyDocumentRule::sourceBlockReason($item);
        $state = $problem !== null ? 'attention' : PropertyDocumentRule::state($active, $issued->pluck('type')->all());

        return [
            'id' => $item->id,
            'description' => $item->poItem->description,
            'unit' => $item->poItem->unit?->name,
            'quantity' => Quantity::format(Quantity::thousandths($item->quantity)),
            'unit_price' => Money::php(Money::cents($item->poItem->unit_price)),
            'iar_number' => $item->delivery->iar_number,
            'po_number' => $item->delivery->purchaseOrder->po_number,
            'delivered_on' => $item->delivered_on?->toDateString(),
            'state' => $state,
            'state_label' => match ($state) {
                PropertyDocumentRule::STATE_PENDING_DECISION => 'Needs equipment decision',
                PropertyDocumentRule::STATE_PENDING_DOCUMENTS => 'Needs '.implode(' + ', array_map(
                    fn (PropertyDocumentType $t) => $t->label(),
                    PropertyDocumentRule::missingTypes($active, $issued->pluck('type')->all()),
                )),
                PropertyDocumentRule::STATE_DONE => 'Done',
                default => 'Source voided: void by hand',
            },
            'band_label' => $active?->band->label(),
            'documents' => $issued->map(fn (PropertyDocument $d) => ['type' => $d->type->label(), 'number' => $d->number])->values()->all(),
            'source_problem' => $problem,
        ];
    }

    /** @return array<string, mixed> */
    private function voidedRow(PropertyDocument $doc): array
    {
        return [
            'id' => $doc->id,
            'number' => $doc->number,
            'type_label' => $doc->type->label(),
            'description' => $doc->decision?->deliveryItem?->poItem?->description,
            'delivery_item_id' => $doc->decision?->delivery_item_id,
            'void_reason' => $doc->void_reason,
            'voided_by' => $doc->voider?->name,
            'voided_at' => $doc->voided_at ? DisplayTime::format($doc->voided_at) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function decisionRow(PropertyDecision $d): array
    {
        return [
            'id' => $d->id,
            'is_equipment' => $d->is_equipment,
            'category_default' => $d->category_default,
            'override_reason' => $d->override_reason,
            'band' => $this->bandInfo($d->band),
            'unit_price' => Money::php($d->unit_price_cents),
            'par_threshold' => Money::php($d->par_threshold_cents),
            'ics_min' => Money::php($d->ics_min_cents),
            'rule_provisional' => $d->rule_provisional,
            'remarks' => $d->remarks,
            'status' => $d->status->value,
            'decided_by' => $d->decider?->name,
            'decided_at' => $d->created_at ? DisplayTime::format($d->created_at) : null,
            'void_reason' => $d->void_reason,
            'voided_by' => $d->voider?->name,
            'voided_at' => $d->voided_at ? DisplayTime::format($d->voided_at) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function documentRow(PropertyDocument $doc, User $user, bool $ofActiveDecision): array
    {
        $void = $doc->status === PropertyDocumentStatus::Issued
            ? $this->gate($user, 'voidDocument', $doc, $doc->type->permission())
            : $this->none();

        return [
            'id' => $doc->id,
            'type' => $doc->type->value,
            'type_label' => $doc->type->label(),
            'number' => $doc->number,
            'status' => $doc->status->value,
            'status_label' => $doc->status->label(),
            'user_name' => $doc->user_name,
            'user_employee_no' => $doc->user_employee_no,
            'user_position' => $doc->user_position,
            'issued_by' => $doc->issuer?->name,
            'issued_at' => $doc->issued_at ? DisplayTime::format($doc->issued_at) : null,
            'void_reason' => $doc->void_reason,
            'voided_by' => $doc->voider?->name,
            'voided_at' => $doc->voided_at ? DisplayTime::format($doc->voided_at) : null,
            'current' => $ofActiveDecision,
            'can_void' => $void,
        ];
    }

    /** @return array<string, mixed> */
    private function bandInfo(PropertyBand $band): array
    {
        return [
            'value' => $band->value,
            'label' => $band->label(),
            'documents' => array_map(fn (PropertyDocumentType $t) => $t->label(), $band->documents()),
            'remarks_required' => $band->requiresRemarks(),
        ];
    }

    /** @return array<string, mixed> */
    private function ruleSummary(): array
    {
        return [
            'provisional' => PropertyDocumentRule::isProvisional(),
            'par_threshold' => Money::php(PropertyDocumentRule::parThresholdCents()),
            'ics_min' => Money::php(PropertyDocumentRule::icsMinCents()),
        ];
    }

    /** @return list<PropertyDocumentType> */
    private function issuedTypes(PropertyDecision $decision): array
    {
        return $decision->documents
            ->filter(fn (PropertyDocument $d) => $d->status === PropertyDocumentStatus::Issued)
            ->pluck('type')->all();
    }

    /**
     * Policy result as {allowed, reason}. The reason is shown only to people who hold the permission, so everybody else
     * just sees no button.
     *
     * @param  array<int, mixed>|mixed  $arguments
     * @return array{allowed: bool, reason: string|null}
     */
    private function gate(User $user, string $ability, mixed $arguments, string $permission): array
    {
        $response = Gate::forUser($user)->inspect($ability, $arguments);

        return [
            'allowed' => $response->allowed(),
            'reason' => $response->allowed() || ! $user->can($permission) ? null : $response->message(),
        ];
    }

    /** @return array{allowed: bool, reason: string|null} */
    private function none(): array
    {
        return ['allowed' => false, 'reason' => null];
    }
}
