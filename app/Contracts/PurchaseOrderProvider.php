<?php

namespace App\Contracts;

use App\Data\PurchaseOrderData;
use App\Enums\PurchaseOrderSource;
use App\Exceptions\BizBoxNotConfigured;
use App\Exceptions\PurchaseOrderNotFound;

/**
 * Where PO details come from. The app talks only to this interface, so the
 * source (our own database now, BizBox later) can change without touching
 * the rest of the code.
 *
 * $reference is the PO number as the person knows it. A provider may accept
 * other references too (the BizBox provider decides once IT confirms how POs
 * are identified, see docs/INTEGRATION-BIZBOX.md).
 *
 * The binding is in AppServiceProvider and follows config('mms.bizbox.enabled').
 */
interface PurchaseOrderProvider
{
    /** Which source this provider stands for (what an import would write into purchase_orders.source). */
    public function source(): PurchaseOrderSource;

    /** False when the source cannot be used right now. Never calls the network. */
    public function isAvailable(): bool;

    /**
     * @throws PurchaseOrderNotFound
     * @throws BizBoxNotConfigured (BizBox provider while BizBox is not set up)
     */
    public function fetch(string $reference): PurchaseOrderData;

    /**
     * Labels of the supporting documents required for this PO (Part 2 completeness gate).
     * The required list is not defined yet, so the local provider returns an empty list.
     *
     * @return list<string>
     *
     * @throws PurchaseOrderNotFound
     * @throws BizBoxNotConfigured
     */
    public function iarChecklist(string $reference): array;
}