<?php

namespace App\Contracts;

use App\Models\ItemCategory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseRepositoryInterface<ItemCategory>
 */
interface ItemCategoryRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Paginated, filtered list (search), ordered by name.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, ItemCategory>
     */
    public function paginateFiltered(array $filters, int $perPage = 15): LengthAwarePaginator;

    /**
     * Categories for a dropdown: active ones, plus $includeId even if it is inactive
     * (so editing an item whose category was deactivated still shows it).
     *
     * @return Collection<int, ItemCategory>
     */
    public function options(?int $includeId = null): Collection;
}
