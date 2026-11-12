<?php

namespace App\Contracts;

use App\Models\Supplier;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseRepositoryInterface<Supplier>
 */
interface SupplierRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Paginated, filtered list (search), ordered by name.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Supplier>
     */
    public function paginateFiltered(array $filters, int $perPage = 15): LengthAwarePaginator;
}