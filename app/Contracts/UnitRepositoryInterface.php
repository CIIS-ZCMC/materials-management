<?php

namespace App\Contracts;

use App\Models\Unit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseRepositoryInterface<Unit>
 */
interface UnitRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Paginated, filtered list (search), ordered by name.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Unit>
     */
    public function paginateFiltered(array $filters, int $perPage = 15): LengthAwarePaginator;

    /**
     * Units for a dropdown: active ones, plus $includeId even if it is inactive.
     *
     * @return Collection<int, Unit>
     */
    public function options(?int $includeId = null): Collection;
}
