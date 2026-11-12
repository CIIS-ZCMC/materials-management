<?php

namespace App\Repositories;

use App\Contracts\SupplierRepositoryInterface;
use App\Models\Supplier;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseRepository<Supplier>
 */
class SupplierRepository extends BaseRepository implements SupplierRepositoryInterface
{
    public function __construct(Supplier $supplier)
    {
        parent::__construct($supplier);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Supplier>
     */
    public function paginateFiltered(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->newQuery()
            ->filter($filters)
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }
}