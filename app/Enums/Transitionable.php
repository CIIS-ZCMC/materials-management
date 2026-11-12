<?php

namespace App\Enums;

/**
 * A status that is STORED on a record and may only move along fixed paths.
 *
 * Implemented by PurchaseOrderStatus, DeliveryStatus, DvStatus and
 * ReplacementStatus. Changes go through App\Support\StatusTransition::apply(),
 * which checks allowedNext() and writes the audit entry.
 *
 * Who may trigger a change is NOT decided here: that is a Policy / permission
 * (config/mms_access.php). This only says which changes are valid at all.
 */
interface Transitionable
{
    /** Text shown to users and written into the audit entry. */
    public function label(): string;

    /**
     * Statuses this one may move to. An empty list means a final status.
     *
     * @return array<int, static>
     */
    public function allowedNext(): array;

    /** True when nothing may follow this status. */
    public function isFinal(): bool;

    /** True when moving from this status to $to needs a written reason. */
    public function requiresReasonFor(Transitionable $to): bool;
}