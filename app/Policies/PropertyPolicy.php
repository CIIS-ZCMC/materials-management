<?php

namespace App\Policies;

use App\Enums\PropertyDocumentStatus;
use App\Enums\PropertyDocumentType;
use App\Models\DeliveryItem;
use App\Models\PropertyDecision;
use App\Models\PropertyDocument;
use App\Models\User;
use App\Support\PropertyDocumentRule;
use Illuminate\Auth\Access\Response;

/**
 * Step 6.2, existing permissions only (the Inventory Team job holds all three):
 *   equipment.decide    record and void an equipment decision
 *   par.generate        issue and void a PAR and its Sub-PAR
 *   ics.generate        issue and void an ICS
 * Each action is also limited by PropertyDocumentRule (the state of the line). Registered for PropertyDecision and
 * PropertyDocument in AppServiceProvider (no auto-discovery, one policy for both models).
 */
class PropertyPolicy
{
    public const DECIDE = 'equipment.decide';

    public const READ_PERMISSIONS = ['equipment.decide', 'par.generate', 'ics.generate'];

    public function viewAny(User $actor): bool
    {
        return $actor->hasAnyPermission(self::READ_PERMISSIONS);
    }

    public function view(User $actor): bool
    {
        return $actor->hasAnyPermission(self::READ_PERMISSIONS);
    }

    /** Called as Gate::authorize('decide', [PropertyDecision::class, $deliveryItem]). */
    public function decide(User $actor, DeliveryItem $item): Response
    {
        if (! $actor->can(self::DECIDE)) {
            return Response::deny();
        }

        $item->loadMissing('delivery');
        $hasActive = PropertyDecision::query()->where('delivery_item_id', $item->id)->where('status', 'active')->exists();
        $reason = PropertyDocumentRule::decideBlockReason($item, $hasActive);

        return $reason === null ? Response::allow() : Response::deny($reason);
    }

    public function voidDecision(User $actor, PropertyDecision $decision): Response
    {
        if (! $actor->can(self::DECIDE)) {
            return Response::deny();
        }

        $issued = $decision->documents()->where('status', PropertyDocumentStatus::Issued->value)->count();
        $reason = PropertyDocumentRule::voidDecisionBlockReason($decision, $issued);

        return $reason === null ? Response::allow() : Response::deny($reason);
    }

    /** Called as Gate::authorize('issue', [$decision, $type]). */
    public function issue(User $actor, PropertyDecision $decision, PropertyDocumentType $type): Response
    {
        if (! $actor->can($type->permission())) {
            return Response::deny();
        }

        $item = DeliveryItem::query()->with('delivery')->findOrFail($decision->delivery_item_id);
        $issuedTypes = $decision->documents()
            ->where('status', PropertyDocumentStatus::Issued->value)
            ->get()->pluck('type')->all();

        $reason = PropertyDocumentRule::issueBlockReason($decision, $item, $type, $issuedTypes);

        return $reason === null ? Response::allow() : Response::deny($reason);
    }

    public function voidDocument(User $actor, PropertyDocument $document): Response
    {
        if (! $actor->can($document->type->permission())) {
            return Response::deny();
        }

        $reason = PropertyDocumentRule::voidDocumentBlockReason($document);

        return $reason === null ? Response::allow() : Response::deny($reason);
    }

    /** Called as Gate::authorize('suggestNumber', [PropertyDecision::class, $type]). */
    public function suggestNumber(User $actor, PropertyDocumentType $type): bool
    {
        return $actor->can($type->permission());
    }
}
