<?php

namespace App\Services;

use App\Contracts\UnitRepositoryInterface;
use App\Models\Unit;
use App\Support\Audit;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UnitService
{
    private const FIELDS = ['name', 'code'];

    public function __construct(
        private readonly UnitRepositoryInterface $units,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Unit>
     */
    public function paginate(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->units->paginateFiltered($filters, $perPage);
    }

    /**
     * @param  array<string, mixed>  $data  validated input
     */
    public function create(array $data): Unit
    {
        return DB::transaction(function () use ($data) {
            $values = Arr::only($data, self::FIELDS);

            $unit = new Unit($values);
            $unit->is_active = true;
            $unit->save();

            Audit::log(
                'created',
                $unit,
                "Created unit {$unit->auditLabel()}",
                new: array_filter($values, fn ($value) => $value !== null),
                log: 'catalog',
            );

            return $unit;
        });
    }

    /**
     * @param  array<string, mixed>  $data  validated input
     */
    public function update(Unit $unit, array $data): Unit
    {
        return DB::transaction(function () use ($unit, $data) {
            $unit->fill(Arr::only($data, self::FIELDS));

            $new = $unit->getDirty();
            $old = Arr::only($unit->getOriginal(), array_keys($new));
            $unit->save();

            if ($new !== []) {
                Audit::log('updated', $unit, "Edited unit {$unit->auditLabel()}", old: $old, new: $new, log: 'catalog');
            }

            return $unit;
        });
    }

    public function setActive(Unit $unit, bool $active): void
    {
        DB::transaction(function () use ($unit, $active) {
            if ($unit->is_active === $active) {
                return; // nothing changes, nothing to log
            }

            $unit->is_active = $active;
            $unit->save();

            Audit::log(
                event: $active ? 'activated' : 'deactivated',
                subject: $unit,
                description: ($active ? 'Activated' : 'Deactivated')." unit {$unit->auditLabel()}",
                old: ['status' => $active ? 'Inactive' : 'Active'],
                new: ['status' => $active ? 'Active' : 'Inactive'],
                log: 'catalog',
            );
        });
    }
}
