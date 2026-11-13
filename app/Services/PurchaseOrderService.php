<?php

namespace App\Services;

use App\Contracts\PurchaseOrderRepositoryInterface;
use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use Illuminate\Pagination\LengthAwarePaginator;

class PurchaseOrderService
{
    public function __construct(
        private readonly PurchaseOrderRepositoryInterface $orders,
    ) {}

    /**
     * Turns the raw query string into safe filter values. Anything invalid is
     * ignored (never an error page); the controller sends the result back to the
     * page so the inputs always show what was really applied.
     *
     * @param  array<string, mixed>  $input
     * @return array{search: string, status: string, supplier: string, from: string, to: string, view: string, sort: string, direction: string}
     */
    public function cleanFilters(array $input): array
    {
        $text = fn (string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';

        [$sort, $direction] = $this->orders->resolveSort(
            $text('sort') !== '' ? $text('sort') : null,
            $text('direction') !== '' ? $text('direction') : null,
        );

        return [
            'search' => $text('search'),
            'status' => PurchaseOrderStatus::tryFrom($text('status'))?->value ?? '',
            'supplier' => ctype_digit($text('supplier')) ? $text('supplier') : '',
            'from' => $this->validDate($text('from')),
            'to' => $this->validDate($text('to')),
            'view' => $text('view') === 'closed' ? 'closed' : 'active',
            'sort' => $sort,
            'direction' => $direction,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters  cleaned by cleanFilters()
     * @return LengthAwarePaginator<int, PurchaseOrder>
     */
    public function paginate(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->orders->paginateFiltered($filters, $perPage);
    }

    /**
     * @return list<array{id: int, name: string, is_active: bool}>
     */
    public function supplierOptions(): array
    {
        return $this->orders->supplierOptions()
            ->map(fn ($supplier) => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'is_active' => $supplier->is_active,
            ])
            ->values()
            ->all();
    }

    public function detail(PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        return $this->orders->loadDetail($purchaseOrder);
    }

    /** Returns the date if it is a real Y-m-d date, otherwise an empty string. */
    private function validDate(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
    }
}