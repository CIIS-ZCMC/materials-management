<?php

namespace App\Services;

use App\Contracts\PersonnelDirectory;
use App\Data\PersonData;
use App\Enums\PropertyDocumentStatus;
use App\Enums\PropertyDocumentType;
use App\Exceptions\InvalidStatusTransition;
use App\Models\DeliveryItem;
use App\Models\PropertyDecision;
use App\Models\PropertyDocument;
use App\Models\User;
use App\Support\Audit;
use App\Support\PropertyDocumentRule;
use App\Support\StatusTransition;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Issuing and voiding PAR, Sub-PAR and ICS (step 6.2).
 *
 *   issue()  PAR: the PAR and its Sub-PAR are issued together in one transaction (the Sub-PAR user is chosen from the
 *            personnel directory, role end-user). Sub-PAR alone: only to re-issue after the Sub-PAR was voided on its
 *            own (for example to name a different user). ICS: one document.
 *   void()   a wrong document is voided with a reason and issued again with a new number, never edited. Voiding a PAR
 *            also voids its Sub-PAR.
 *
 * Locks the decision row first, then the series rows (inside PropertyNumberService). The rule is checked again under
 * the lock. Voids go through StatusTransition. Audit log "property".
 */
class PropertyDocumentService
{
    public function __construct(
        private readonly PropertyNumberService $numbers,
        private readonly PersonnelDirectory $directory,
    ) {}

    /**
     * @return list<PropertyDocument> the documents that were issued
     */
    public function issue(
        PropertyDecision $decision,
        PropertyDocumentType $type,
        string $number,
        ?string $subParNumber,
        int|string|null $personId,
        User $actor,
    ): array {
        return DB::transaction(function () use ($decision, $type, $number, $subParNumber, $personId, $actor): array {
            $row = PropertyDecision::query()->whereKey($decision->getKey())->lockForUpdate()->firstOrFail();
            $item = DeliveryItem::query()->with(['delivery', 'poItem.unit'])->findOrFail($row->delivery_item_id);

            $issued = PropertyDocument::query()
                ->where('property_decision_id', $row->id)
                ->where('status', PropertyDocumentStatus::Issued->value)
                ->lockForUpdate()
                ->get();

            $issuedTypes = $issued->pluck('type')->all();

            if ($reason = PropertyDocumentRule::issueBlockReason($row, $item, $type, $issuedTypes)) {
                throw ValidationException::withMessages(['form' => $reason]);
            }

            $year = $this->numbers->currentYear();
            $person = $type === PropertyDocumentType::Ics ? null : $this->endUser($personId);

            if ($type === PropertyDocumentType::Par && trim((string) $subParNumber) === '') {
                throw ValidationException::withMessages(['sub_par_number' => 'Confirm the Sub-PAR number.']);
            }

            $made = [];

            if ($type === PropertyDocumentType::Par) {
                $par = $this->make($row, $item, PropertyDocumentType::Par, $year, $number, 'number', null, null, $actor);
                $made[] = $par;
                $made[] = $this->make($row, $item, PropertyDocumentType::SubPar, $year, (string) $subParNumber, 'sub_par_number', $par, $person, $actor);
            } elseif ($type === PropertyDocumentType::SubPar) {
                /** @var PropertyDocument $parent */
                $parent = $issued->firstWhere('type', PropertyDocumentType::Par);
                $made[] = $this->make($row, $item, PropertyDocumentType::SubPar, $year, $number, 'number', $parent, $person, $actor);
            } else {
                $made[] = $this->make($row, $item, PropertyDocumentType::Ics, $year, $number, 'number', null, null, $actor);
            }

            return $made;
        });
    }

    /**
     * Voids one document. A PAR takes its issued Sub-PAR with it. Returns every document that was voided.
     *
     * @return list<PropertyDocument>
     */
    public function void(PropertyDocument $document, string $reason, User $actor): array
    {
        $reason = trim($reason);

        if ($error = PropertyDocumentRule::reasonError($reason)) {
            throw ValidationException::withMessages(['reason' => $error]);
        }

        return DB::transaction(function () use ($document, $reason, $actor): array {
            $row = PropertyDocument::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();

            if ($blocked = PropertyDocumentRule::voidDocumentBlockReason($row)) {
                throw ValidationException::withMessages(['form' => $blocked]);
            }

            $voided = [$this->voidOne($row, $reason, $actor)];

            if ($row->type === PropertyDocumentType::Par) {
                $children = PropertyDocument::query()
                    ->where('parent_id', $row->id)
                    ->where('status', PropertyDocumentStatus::Issued->value)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($children as $child) {
                    $voided[] = $this->voidOne($child, "PAR {$row->number} was voided: {$reason}", $actor);
                }
            }

            return $voided;
        });
    }

    // ---- internals -------------------------------------------------------------------------------

    private function voidOne(PropertyDocument $document, string $reason, User $actor): PropertyDocument
    {
        try {
            StatusTransition::apply(
                $document,
                'status',
                PropertyDocumentStatus::Voided,
                reason: $reason,
                extra: [
                    'document_type' => $document->type->label(),
                    'document_number' => $document->number,
                    'delivery_item_id' => $document->decision?->delivery_item_id,
                ],
                log: 'property',
            );
        } catch (InvalidStatusTransition $e) {
            throw ValidationException::withMessages(['form' => $e->getMessage()]);
        }

        $document->forceFill([
            'void_reason' => $reason,
            'voided_by' => $actor->id,
            'voided_at' => now(),
        ])->save();

        return $document;
    }

    private function make(
        PropertyDecision $decision,
        DeliveryItem $item,
        PropertyDocumentType $type,
        int $year,
        string $number,
        string $field,
        ?PropertyDocument $parent,
        ?PersonData $person,
        User $actor,
    ): PropertyDocument {
        $claim = $this->numbers->claim($type, $year, $number, $field);

        $document = new PropertyDocument;
        $document->forceFill([
            'type' => $type,
            'number' => $claim['number'],
            'property_series_id' => $claim['series']->id,
            'sequence' => $claim['sequence'],
            'property_decision_id' => $decision->id,
            'parent_id' => $parent?->id,
            'status' => PropertyDocumentStatus::Issued,
            'user_person_id' => $person ? (string) $person->id : null,
            'user_employee_no' => $person?->employeeNo,
            'user_name' => $person?->name,
            'user_position' => $person?->position,
            'issued_by' => $actor->id,
            'issued_at' => now(),
        ]);

        try {
            $document->save();
        } catch (UniqueConstraintViolationException) {
            throw $this->numbers->duplicateFor($claim['number'], $field);
        }

        Audit::log(
            event: 'property_document_issued',
            subject: $document,
            description: "{$type->label()} {$document->number} issued for ".$item->auditLabel(),
            new: [
                'document' => $type->label(),
                'document_number' => $document->number,
                'sub_par_user' => $person ? "{$person->name} ({$person->employeeNo})" : null,
            ],
            extra: [
                'delivery_item_id' => $item->id,
                'delivery_id' => $item->delivery_id,
                'property_decision_id' => $decision->id,
                'parent_id' => $parent?->id,
            ],
            causer: $actor,
            log: 'property',
        );

        return $document;
    }

    /**
     * The Sub-PAR user must be an ACTIVE end-user of the personnel directory. find() also returns inactive people, so
     * the person is looked up again through search(), which only returns active people of that role.
     */
    private function endUser(int|string|null $personId): PersonData
    {
        if ($personId === null || $personId === '') {
            throw ValidationException::withMessages(['person_id' => 'Choose the user the Sub-PAR is issued to.']);
        }

        $person = $this->directory->find($personId);

        if ($person === null) {
            throw ValidationException::withMessages(['person_id' => 'This person was not found in the personnel directory.']);
        }

        $match = collect($this->directory->search($person->employeeNo, PersonnelDirectory::ROLE_END_USER, PersonnelDirectory::MAX_LIMIT))
            ->first(fn (PersonData $p) => (string) $p->id === (string) $person->id);

        if ($match === null) {
            throw ValidationException::withMessages(['person_id' => 'The Sub-PAR user must be an active end-user in the personnel directory.']);
        }

        return $match;
    }
}
