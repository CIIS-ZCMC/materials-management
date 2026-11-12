<?php

namespace App\Services;

use App\Contracts\ItemCategoryRepositoryInterface;
use App\Models\ItemCategory;
use App\Support\Audit;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ItemCategoryService
{
    private const FIELDS = ['name', 'is_equipment'];

    public function __construct(
        private readonly ItemCategoryRepositoryInterface $categories,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, ItemCategory>
     */
    public function paginate(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->categories->paginateFiltered($filters, $perPage);
    }

    /**
     * @param  array<string, mixed>  $data  validated input
     */
    public function create(array $data): ItemCategory
    {
        return DB::transaction(function () use ($data) {
            $values = Arr::only($data, self::FIELDS);

            $category = new ItemCategory($values);
            $category->is_active = true;
            $category->save();

            Audit::log(
                'created',
                $category,
                "Created category {$category->auditLabel()}",
                new: $this->present($values),
                log: 'catalog',
            );

            return $category;
        });
    }

    /**
     * @param  array<string, mixed>  $data  validated input
     */
    public function update(ItemCategory $category, array $data): ItemCategory
    {
        return DB::transaction(function () use ($category, $data) {
            $category->fill(Arr::only($data, self::FIELDS));

            $new = $category->getDirty();
            $old = Arr::only($category->getOriginal(), array_keys($new));
            $category->save();

            if ($new !== []) {
                Audit::log(
                    'updated',
                    $category,
                    "Edited category {$category->auditLabel()}",
                    old: $this->present($old),
                    new: $this->present($new),
                    log: 'catalog',
                );
            }

            return $category;
        });
    }

    public function setActive(ItemCategory $category, bool $active): void
    {
        DB::transaction(function () use ($category, $active) {
            if ($category->is_active === $active) {
                return; // nothing changes, nothing to log
            }

            $category->is_active = $active;
            $category->save();

            Audit::log(
                event: $active ? 'activated' : 'deactivated',
                subject: $category,
                description: ($active ? 'Activated' : 'Deactivated')." category {$category->auditLabel()}",
                old: ['status' => $active ? 'Inactive' : 'Active'],
                new: ['status' => $active ? 'Active' : 'Inactive'],
                log: 'catalog',
            );
        });
    }

    /**
     * Audit values are shown as text, so the flag is written as Yes / No.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function present(array $values): array
    {
        if (array_key_exists('is_equipment', $values)) {
            $values['is_equipment'] = filter_var($values['is_equipment'], FILTER_VALIDATE_BOOLEAN) ? 'Yes' : 'No';
        }

        return $values;
    }
}
