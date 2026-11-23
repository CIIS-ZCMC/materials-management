<?php

namespace App\Contracts;

use App\Models\Personnel;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends BaseRepositoryInterface<Personnel>
 */
interface PersonnelRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Active people only, filtered by "search" and "role", ordered by name.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Personnel>
     */
    public function searchActive(array $filters, int $limit = 20): Collection;
}