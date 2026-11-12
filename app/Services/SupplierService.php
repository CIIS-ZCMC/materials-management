<?php

namespace App\Services;

use App\Contracts\SupplierRepositoryInterface;
use App\Models\Supplier;
use App\Support\Audit;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SupplierService
{
    private const FIELDS = ['name', 'email', 'contact_person', 'address'];

    public function __construct(
        private readonly SupplierRepositoryInterface $suppliers,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Supplier>
     */
    public function paginate(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->suppliers->paginateFiltered($filters, $perPage);
    }

    /**
     * @param  array<string, mixed>  $data  validated input
     */
    public function create(array $data): Supplier
    {
        return DB::transaction(function () use ($data) {
            $values = Arr::only($data, self::FIELDS);

            $supplier = new Supplier($values);
            $supplier->is_active = true;
            $supplier->save();

            Audit::log(
                'created',
                $supplier,
                "Created supplier {$supplier->auditLabel()}",
                new: array_filter($values, fn ($value) => $value !== null),
                log: 'suppliers',
            );

            return $supplier;
        });
    }

    /**
     * @param  array<string, mixed>  $data  validated input
     */
    public function update(Supplier $supplier, array $data): Supplier
    {
        return DB::transaction(function () use ($supplier, $data) {
            $supplier->fill(Arr::only($data, self::FIELDS));

            $new = $supplier->getDirty();
            $old = Arr::only($supplier->getOriginal(), array_keys($new));
            $supplier->save();

            if ($new !== []) {
                Audit::log('updated', $supplier, "Edited supplier {$supplier->auditLabel()}", old: $old, new: $new, log: 'suppliers');
            }

            return $supplier;
        });
    }

    public function setActive(Supplier $supplier, bool $active): void
    {
        DB::transaction(function () use ($supplier, $active) {
            if ($supplier->is_active === $active) {
                return; // nothing changes, nothing to log
            }

            $supplier->is_active = $active;
            $supplier->save();

            Audit::log(
                event: $active ? 'activated' : 'deactivated',
                subject: $supplier,
                description: ($active ? 'Activated' : 'Deactivated')." supplier {$supplier->auditLabel()}",
                old: ['status' => $active ? 'Inactive' : 'Active'],
                new: ['status' => $active ? 'Active' : 'Inactive'],
                log: 'suppliers',
            );
        });
    }
}