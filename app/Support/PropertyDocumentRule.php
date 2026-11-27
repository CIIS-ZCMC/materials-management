<?php

namespace App\Support;

use App\Enums\DeliveryItemStatus;
use App\Enums\DeliveryStatus;
use App\Enums\PropertyBand;
use App\Enums\PropertyDecisionStatus;
use App\Enums\PropertyDocumentStatus;
use App\Enums\PropertyDocumentType;
use App\Models\DeliveryItem;
use App\Models\PropertyDecision;
use App\Models\PropertyDocument;

/**
 * The one place the property rules live (step 6.2), like DeliveryItemRule. Each "...BlockReason" method returns null
 * when allowed, or a plain sentence saying why not. PropertyPolicy (buttons, 403) and the Services (again, under row
 * locks) both use it.
 *
 * THE RULE (PROVISIONAL, PHASE-6-PLAN Q4; the thresholds are in config/mms.php "property"). It looks at ONE unit's price:
 *   not equipment ................................ decision only, nothing to issue
 *   equipment, price above par_threshold ......... PAR + Sub-PAR
 *   equipment, ics_min_value up to and including
 *   par_threshold ................................ ICS
 *   equipment, price below ics_min_value ......... neither; remarks are mandatory
 * Money is compared in whole centavos (Money::cents), so 49,999.995 style rounding never decides a band.
 *
 * WHICH ROWS: a delivered-item row that is ACTIVE and whose delivery is not voided. A closed PO does NOT block
 * (Part 3 can still be pending when the PO is closed; default of step 6.2, easy to change in sourceBlockReason()).
 * If the delivery or the row is voided AFTER a decision or document exists, nothing is changed automatically: the
 * property page lists the line under "Needs attention" and staff void the decision and documents by hand.
 */
final class PropertyDocumentRule
{
    public const STATE_PENDING_DECISION = 'pending_decision';

    public const STATE_PENDING_DOCUMENTS = 'pending_documents';

    public const STATE_DONE = 'done';

    public static function parThresholdCents(): int
    {
        return Money::cents(config('mms.property.par_threshold', 50000));
    }

    public static function icsMinCents(): int
    {
        return Money::cents(config('mms.property.ics_min_value', 1000));
    }

    public static function isProvisional(): bool
    {
        return (bool) config('mms.property.provisional', true);
    }

    public static function band(bool $isEquipment, int $unitPriceCents, ?int $parCents = null, ?int $icsMinCents = null): PropertyBand
    {
        $parCents ??= self::parThresholdCents();
        $icsMinCents ??= self::icsMinCents();

        if (! $isEquipment) {
            return PropertyBand::NotEquipment;
        }

        if ($unitPriceCents > $parCents) {
            return PropertyBand::Par;
        }

        if ($unitPriceCents >= $icsMinCents) {
            return PropertyBand::Ics;
        }

        return PropertyBand::Below;
    }

    /** A line can be worked on only while it counts: row active and delivery not voided. $item->delivery must be loaded. */
    public static function sourceBlockReason(DeliveryItem $item): ?string
    {
        if ($item->status !== DeliveryItemStatus::Active) {
            return 'This delivered line was voided, so it has no property documents to issue.';
        }

        if ($item->delivery->status === DeliveryStatus::Voided) {
            return 'The delivery of this line was voided, so it has no property documents to issue.';
        }

        return null;
    }

    public static function decideBlockReason(DeliveryItem $item, bool $hasActiveDecision): ?string
    {
        if ($reason = self::sourceBlockReason($item)) {
            return $reason;
        }

        if ($hasActiveDecision) {
            return 'This line already has an equipment decision. Void it first to decide again.';
        }

        return null;
    }

    /** A decision with issued documents cannot be voided: void the documents first. */
    public static function voidDecisionBlockReason(PropertyDecision $decision, int $issuedDocuments): ?string
    {
        if ($decision->status !== PropertyDecisionStatus::Active) {
            return 'This decision was already voided.';
        }

        if ($issuedDocuments > 0) {
            return 'Void the issued document(s) of this decision first.';
        }

        return null;
    }

    /**
     * @param  list<PropertyDocumentType>  $issuedTypes  the types that are ISSUED (not void) for this decision
     */
    public static function issueBlockReason(PropertyDecision $decision, DeliveryItem $item, PropertyDocumentType $type, array $issuedTypes): ?string
    {
        if ($decision->status !== PropertyDecisionStatus::Active) {
            return 'This decision was voided. Record a new decision first.';
        }

        if ($reason = self::sourceBlockReason($item)) {
            return $reason;
        }

        if (! in_array($type, $decision->band->documents(), true)) {
            return "This decision ({$decision->band->label()}) does not call for a {$type->label()}.";
        }

        if (in_array($type, $issuedTypes, true)) {
            return "A {$type->label()} is already issued for this line. Void it first to issue a new one.";
        }

        if ($type === PropertyDocumentType::SubPar && ! in_array(PropertyDocumentType::Par, $issuedTypes, true)) {
            return 'Issue the PAR first. The Sub-PAR goes with it.';
        }

        if ($type === PropertyDocumentType::Par && in_array(PropertyDocumentType::SubPar, $issuedTypes, true)) {
            return 'A Sub-PAR is issued without a PAR. Void it first.';
        }

        return null;
    }

    public static function voidDocumentBlockReason(PropertyDocument $document): ?string
    {
        if ($document->status !== PropertyDocumentStatus::Issued) {
            return 'This document was already voided.';
        }

        return null;
    }

    /**
     * The documents the decision still needs.
     *
     * @param  list<PropertyDocumentType>  $issuedTypes
     * @return list<PropertyDocumentType>
     */
    public static function missingTypes(PropertyDecision $decision, array $issuedTypes): array
    {
        return array_values(array_filter(
            $decision->band->documents(),
            fn (PropertyDocumentType $type) => ! in_array($type, $issuedTypes, true),
        ));
    }

    /** What the list shows for one line. $active is the ACTIVE decision of the line, if any. */
    public static function state(?PropertyDecision $active, array $issuedTypes): string
    {
        if ($active === null) {
            return self::STATE_PENDING_DECISION;
        }

        return self::missingTypes($active, $issuedTypes) === [] ? self::STATE_DONE : self::STATE_PENDING_DOCUMENTS;
    }

    public static function reasonError(?string $reason): ?string
    {
        $length = mb_strlen(trim((string) $reason));

        return $length < 5 || $length > 500 ? 'Give a reason of 5 to 500 characters.' : null;
    }
}
