<?php

namespace App\Contracts;

use App\Models\Item;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseRepositoryInterface<Item>
 */
interface ItemRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Paginated, filtered list (search), with category and unit loaded, ordered by name.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Item>
     */
    public function paginateFiltered(array $filters, int $perPage = 15): LengthAwarePaginator;
}
