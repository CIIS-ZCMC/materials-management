<?php

namespace App\Policies;

use App\Models\Delivery;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\DeliveryRule;
use Illuminate\Auth\Access\Response;

/**
 * delivery.encode   record, edit and void a delivery (the Encoder job). Each only while DeliveryRule allows it.
 * Reading           po.view or any delivery.* permission (the Deliveries list page is still behind the
 *                   section.deliveries gate in routes/web.php).
 * Existing permissions only: nothing was added in step 5.2.
 * Auto-discovered: App\Models\Delivery -> App\Policies\DeliveryPolicy.
 */
class DeliveryPolicy
{
    public const ENCODE = 'delivery.encode';

    public const READ_PERMISSIONS = [
        'po.view',
        'delivery.encode',
        'delivery.upload-docs',
        'delivery.update-items',
    ];

    public function viewAny(User $actor): bool
    {
        return $actor->hasAnyPermission(self::READ_PERMISSIONS);
    }

    public function view(User $actor, Delivery $delivery): bool
    {
        return $actor->hasAnyPermission(self::READ_PERMISSIONS);
    }

    /** Called as Gate::authorize('create', [Delivery::class, $purchaseOrder]). Returns a Response so a blocked PO gives a clear 403 message. */
    public function create(User $actor, PurchaseOrder $purchaseOrder): Response
    {
        if (! $actor->can(self::ENCODE)) {
            return Response::deny();
        }

        $reason = DeliveryRule::addBlockReason($purchaseOrder);

        return $reason === null ? Response::allow() : Response::deny($reason);
    }

    /** Asking for a suggested IAR number needs the same permission as recording a delivery. */
    public function suggestIar(User $actor): bool
    {
        return $actor->can(self::ENCODE);
    }

    public function update(User $actor, Delivery $delivery): Response
    {
        if (! $actor->can(self::ENCODE)) {
            return Response::deny();
        }

        $delivery->loadMissing('purchaseOrder');
        $reason = DeliveryRule::editBlockReason($delivery);

        return $reason === null ? Response::allow() : Response::deny($reason);
    }

    /** Who may void: the same people who record (default proposal, awaiting MMS). Change it here only. */
    public function void(User $actor, Delivery $delivery): Response
    {
        if (! $actor->can(self::ENCODE)) {
            return Response::deny();
        }

        $delivery->loadMissing('purchaseOrder');
        $reason = DeliveryRule::voidBlockReason($delivery);

        return $reason === null ? Response::allow() : Response::deny($reason);
    }
}
