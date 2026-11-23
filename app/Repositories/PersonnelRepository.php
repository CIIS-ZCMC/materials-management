<?php

namespace App\Repositories;

use App\Contracts\PersonnelRepositoryInterface;
use App\Models\Personnel;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends BaseRepository<Personnel>
 */
class PersonnelRepository extends BaseRepository implements PersonnelRepositoryInterface
{
    public function __construct(Personnel $personnel)
    {
        parent::__construct($personnel);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Personnel>
     */
    public function searchActive(array $filters, int $limit = 20): Collection
    {
        return $this->model->newQuery()
            ->where('is_active', true)
            ->filter($filters)
            ->orderBy('name')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }
}