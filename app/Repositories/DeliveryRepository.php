<?php

namespace App\Repositories;

use App\Contracts\DeliveryRepositoryInterface;
use App\Models\Delivery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class DeliveryRepository implements DeliveryRepositoryInterface
{
    public function paginateFiltered(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true) ? $filters['sort'] : 'date_received';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $query = Delivery::query()->with(['purchaseOrder.supplier', 'signatories']);

        $search = (string) ($filters['search'] ?? '');

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';

            $query->where(function (Builder $q) use ($like) {
                $q->where('iar_number', 'like', $like)
                    ->orWhere('invoice_no', 'like', $like)
                    ->orWhereHas('purchaseOrder', function (Builder $po) use ($like) {
                        $po->where('po_number', 'like', $like)
                            ->orWhereHas('supplier', fn (Builder $s) => $s->where('name', 'like', $like));
                    });
            });
        }

        if (($filters['status'] ?? '') !== '') {
            $query->where('status', $filters['status']);
        }

        return $query
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function forPurchaseOrder(int $purchaseOrderId): Collection
    {
        return Delivery::query()
            ->with(['purchaseOrder.supplier', 'signatories'])
            ->where('purchase_order_id', $purchaseOrderId)
            ->orderByDesc('date_received')
            ->orderByDesc('id')
            ->get();
    }

    public function findForUpdate(int|string $id): Delivery
    {
        return Delivery::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }
}
