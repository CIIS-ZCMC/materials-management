<?php

namespace App\Repositories;

use App\Contracts\UnitRepositoryInterface;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseRepository<Unit>
 */
class UnitRepository extends BaseRepository implements UnitRepositoryInterface
{
    public function __construct(Unit $unit)
    {
        parent::__construct($unit);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Unit>
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
     * @return Collection<int, Unit>
     */
    public function options(?int $includeId = null): Collection
    {
        return $this->model->newQuery()
            ->where(function ($q) use ($includeId) {
                $q->where('is_active', true)
                    ->when($includeId, fn ($q) => $q->orWhere('id', $includeId));
            })
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'is_active']);
    }
}
