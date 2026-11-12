<?php

namespace App\Repositories;

use App\Contracts\ItemCategoryRepositoryInterface;
use App\Models\ItemCategory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseRepository<ItemCategory>
 */
class ItemCategoryRepository extends BaseRepository implements ItemCategoryRepositoryInterface
{
    public function __construct(ItemCategory $category)
    {
        parent::__construct($category);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, ItemCategory>
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

    /**
     * @return Collection<int, ItemCategory>
     */
    public function options(?int $includeId = null): Collection
    {
        return $this->model->newQuery()
            ->where(function ($q) use ($includeId) {
                $q->where('is_active', true)
                    ->when($includeId, fn ($q) => $q->orWhere('id', $includeId));
            })
            ->orderBy('name')
            ->get(['id', 'name', 'is_equipment', 'is_active']);
    }
}
