<?php

namespace App\Repositories;

use App\Contracts\PropertyRepositoryInterface;
use App\Enums\DeliveryItemStatus;
use App\Enums\DeliveryStatus;
use App\Enums\PropertyBand;
use App\Enums\PropertyDecisionStatus;
use App\Enums\PropertyDocumentStatus;
use App\Enums\PropertyDocumentType;
use App\Models\DeliveryItem;
use App\Models\PropertyDecision;
use App\Models\PropertyDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class PropertyRepository implements PropertyRepositoryInterface
{
    public function paginateItems(string $tab, string $search, int $perPage): LengthAwarePaginator
    {
        return $this->query($tab, $search)
            ->with([
                'delivery.purchaseOrder',
                'poItem.unit',
                'poItem.category',
                'propertyDecisions.documents',
            ])
            ->orderByDesc('delivery_items.delivered_on')
            ->orderByDesc('delivery_items.id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function paginateVoidedDocuments(string $search, int $perPage): LengthAwarePaginator
    {
        $query = PropertyDocument::query()
            ->with(['voider:id,name', 'decision.deliveryItem.poItem'])
            ->where('status', PropertyDocumentStatus::Voided->value)
            ->orderByDesc('voided_at')
            ->orderByDesc('id');

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(fn ($q) => $q->where('number', 'like', $like)->orWhere('void_reason', 'like', $like));
        }

        return $query->paginate($perPage)->withQueryString();
    }

    public function count(string $tab): int
    {
        if ($tab === self::TAB_VOIDED) {
            return PropertyDocument::query()->where('status', PropertyDocumentStatus::Voided->value)->count();
        }

        return $this->query($tab, '')->count();
    }

    public function decisionsFor(int $deliveryItemId): Collection
    {
        return PropertyDecision::query()
            ->with([
                'decider:id,name',
                'voider:id,name',
                'documents' => fn ($q) => $q->orderBy('id'),
                'documents.issuer:id,name',
                'documents.voider:id,name',
            ])
            ->where('delivery_item_id', $deliveryItemId)
            ->orderByDesc('id')
            ->get();
    }

    // ---- internals -------------------------------------------------------------------------------

    /** @return Builder<DeliveryItem> */
    private function query(string $tab, string $search): Builder
    {
        $query = DeliveryItem::query();

        match ($tab) {
            self::TAB_DONE => $this->eligible($query)
                ->whereHas('propertyDecisions', $this->active())
                ->whereDoesntHave('propertyDecisions', $this->active(missingDocuments: true)),
            self::TAB_ATTENTION => $query
                ->where(fn (Builder $w) => $w->where('delivery_items.status', '!=', DeliveryItemStatus::Active->value)
                    ->orWhereHas('delivery', fn ($d) => $d->where('status', DeliveryStatus::Voided->value)))
                ->whereHas('propertyDecisions', $this->active()),
            default => $this->eligible($query)->where(
                fn (Builder $w) => $w->whereDoesntHave('propertyDecisions', $this->active())
                    ->orWhereHas('propertyDecisions', $this->active(missingDocuments: true)),
            ),
        };

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(fn (Builder $w) => $w
                ->whereHas('poItem', fn ($p) => $p->where('description', 'like', $like))
                ->orWhereHas('delivery', fn ($d) => $d->where('iar_number', 'like', $like)
                    ->orWhereHas('purchaseOrder', fn ($po) => $po->where('po_number', 'like', $like)))
                ->orWhereHas('propertyDecisions.documents', fn ($doc) => $doc->where('number', 'like', $like)));
        }

        return $query;
    }

    /** An ACTIVE row whose delivery is not voided. */
    private function eligible(Builder $query): Builder
    {
        return $query
            ->where('delivery_items.status', DeliveryItemStatus::Active->value)
            ->whereHas('delivery', fn ($d) => $d->where('status', '!=', DeliveryStatus::Voided->value));
    }

    /**
     * For whereHas / whereDoesntHave on "propertyDecisions": the ACTIVE decision, optionally only when a document the
     * band calls for is still not issued (PAR or Sub-PAR for "par", ICS for "ics").
     */
    private function active(bool $missingDocuments = false): \Closure
    {
        $issued = fn (PropertyDocumentType $type) => fn ($doc) => $doc
            ->where('type', $type->value)
            ->where('status', PropertyDocumentStatus::Issued->value);

        return function ($decision) use ($missingDocuments, $issued) {
            $decision->where('status', PropertyDecisionStatus::Active->value);

            if (! $missingDocuments) {
                return;
            }

            $decision->where(function ($w) use ($issued) {
                $w->where(fn ($x) => $x->where('band', PropertyBand::Par->value)
                    ->where(fn ($y) => $y->whereDoesntHave('documents', $issued(PropertyDocumentType::Par))
                        ->orWhereDoesntHave('documents', $issued(PropertyDocumentType::SubPar))))
                    ->orWhere(fn ($x) => $x->where('band', PropertyBand::Ics->value)
                        ->whereDoesntHave('documents', $issued(PropertyDocumentType::Ics)));
            });
        };
    }
}
