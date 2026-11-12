<?php

namespace App\Services;

use App\Contracts\ItemCategoryRepositoryInterface;
use App\Contracts\ItemRepositoryInterface;
use App\Contracts\UnitRepositoryInterface;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Unit;
use App\Support\Audit;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ItemService
{
    private const FIELDS = ['name', 'category_id', 'unit_id'];

    public function __construct(
        private readonly ItemRepositoryInterface $items,
        private readonly ItemCategoryRepositoryInterface $categories,
        private readonly UnitRepositoryInterface $units,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Item>
     */
    public function paginate(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->items->paginateFiltered($filters, $perPage);
    }

    /**
     * Dropdown choices for the item form. Active categories and units only,
     * plus the item's current ones (even if inactive) when editing.
     *
     * @return array{categories: list<array<string, mixed>>, units: list<array<string, mixed>>}
     */
    public function formOptions(?Item $item = null): array
    {
        return [
            'categories' => $this->categories->options($item?->category_id)
                ->map(fn (ItemCategory $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'is_equipment' => $category->is_equipment,
                    'is_active' => $category->is_active,
                ])
                ->values()
                ->all(),
            'units' => $this->units->options($item?->unit_id)
                ->map(fn (Unit $unit) => [
                    'id' => $unit->id,
                    'name' => $unit->name,
                    'code' => $unit->code,
                    'is_active' => $unit->is_active,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data  validated input
     */
    public function create(array $data): Item
    {
        return DB::transaction(function () use ($data) {
            $values = Arr::only($data, self::FIELDS);

            $item = new Item($values);
            $item->is_active = true;
            $item->save();

            Audit::log(
                'created',
                $item,
                "Created item {$item->auditLabel()}",
                new: $this->present($values),
                log: 'catalog',
            );

            return $item;
        });
    }

    /**
     * @param  array<string, mixed>  $data  validated input
     */
    public function update(Item $item, array $data): Item
    {
        return DB::transaction(function () use ($item, $data) {
            $item->fill(Arr::only($data, self::FIELDS));

            $new = $item->getDirty();
            $old = Arr::only($item->getOriginal(), array_keys($new));
            $item->save();

            if ($new !== []) {
                Audit::log(
                    'updated',
                    $item,
                    "Edited item {$item->auditLabel()}",
                    old: $this->present($old),
                    new: $this->present($new),
                    log: 'catalog',
                );
            }

            return $item;
        });
    }

    public function setActive(Item $item, bool $active): void
    {
        DB::transaction(function () use ($item, $active) {
            if ($item->is_active === $active) {
                return; // nothing changes, nothing to log
            }

            $item->is_active = $active;
            $item->save();

            Audit::log(
                event: $active ? 'activated' : 'deactivated',
                subject: $item,
                description: ($active ? 'Activated' : 'Deactivated')." item {$item->auditLabel()}",
                old: ['status' => $active ? 'Inactive' : 'Active'],
                new: ['status' => $active ? 'Active' : 'Inactive'],
                log: 'catalog',
            );
        });
    }

    /**
     * The audit log should show names, not ids: category_id / unit_id become
     * "category" / "unit" with the name.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function present(array $values): array
    {
        $out = [];

        foreach ($values as $key => $value) {
            if ($key === 'category_id') {
                $out['category'] = ItemCategory::query()->whereKey($value)->value('name');
            } elseif ($key === 'unit_id') {
                $out['unit'] = Unit::query()->whereKey($value)->value('name');
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
