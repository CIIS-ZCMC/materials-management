<?php

namespace App\Repositories;

use App\Contracts\ItemRepositoryInterface;
use App\Models\Item;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseRepository<Item>
 */
class ItemRepository extends BaseRepository implements ItemRepositoryInterface
{
    public function __construct(Item $item)
    {
        parent::__construct($item);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Item>
     */
    public function paginateFiltered(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->newQuery()
            ->with(['category:id,name,is_equipment', 'unit:id,name,code'])
            ->filter($filters)
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }
}
