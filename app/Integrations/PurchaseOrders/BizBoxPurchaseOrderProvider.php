<?php

namespace App\Integrations\PurchaseOrders;

use App\Contracts\PurchaseOrderProvider;
use App\Data\PurchaseOrderData;
use App\Enums\PurchaseOrderSource;
use App\Exceptions\BizBoxNotConfigured;

/**
 * STUB. BizBox access does not exist yet (CONTEXT.md decision 3), so this class
 * makes no network call and throws BizBoxNotConfigured for anything that needs data.
 *
 * Phase 13.1 replaces the method bodies once IT answers the questions in
 * docs/INTEGRATION-BIZBOX.md. All BizBox-specific code (connection, field
 * names, translating BizBox data into PurchaseOrderData) belongs in THIS class
 * only, so the rest of the app does not change.
 *
 * AppServiceProvider creates it only when config('mms.bizbox.enabled') is true.
 */
final class BizBoxPurchaseOrderProvider implements PurchaseOrderProvider
{
    public function source(): PurchaseOrderSource
    {
        return PurchaseOrderSource::BizBox;
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function fetch(string $reference): PurchaseOrderData
    {
        throw new BizBoxNotConfigured;
    }

    public function iarChecklist(string $reference): array
    {
        throw new BizBoxNotConfigured;
    }
}