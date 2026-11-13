<?php

namespace App\Http\Requests;

use App\Models\Item;
use App\Models\PurchaseOrder;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SavePurchaseOrderRequest extends FormRequest
{
    public function authorize(): Response|bool
    {
        $po = $this->route('purchaseOrder');

        return $po instanceof PurchaseOrder
            ? Gate::inspect('update', $po)
            : Gate::inspect('create', PurchaseOrder::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $po = $this->currentPo();

        // A new PO needs an ACTIVE supplier. An existing PO may keep its current
        // supplier even if that supplier is inactive now.
        $supplier = Rule::exists('suppliers', 'id')->where(function ($query) use ($po) {
            $query->where(function ($q) use ($po) {
                $q->where('is_active', true);

                if ($po !== null) {
                    $q->orWhere('id', $po->supplier_id);
                }
            });
        });

        return [
            'po_number' => [
                'required', 'string', 'max:30', 'regex:/^[A-Za-z0-9._\/-]+$/',
                Rule::unique('purchase_orders', 'po_number')->ignore($po?->id),
            ],
            'supplier_id' => ['required', 'integer', $supplier],
            'po_date' => ['required', 'date_format:Y-m-d'],
            'emailed_date' => ['nullable', 'date_format:Y-m-d'],
            'delivery_term_days' => ['nullable', 'integer', 'min:0', 'max:365'],

            'schedule_dates' => ['nullable', 'array', 'max:50'],
            'schedule_dates.*' => ['required', 'date_format:Y-m-d', 'distinct'],

            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.item_id' => ['nullable', 'integer', 'exists:items,id'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:99999999999.99', 'decimal:0,2'],
            'items.*.quantity_ordered' => ['required', 'integer', 'min:1', 'max:100000000'],
        ];
    }

    /**
     * Line rules that need the database: a NEW line needs an active catalog item;
     * an EXISTING line may keep its item even if it is inactive or missing (item_id null),
     * and must belong to this PO. One query for the items, one for the PO's lines.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $lines = $this->input('items');

            if (! is_array($lines)) {
                return;
            }

            $po = $this->currentPo();

            /** @var \Illuminate\Support\Collection<int, int|null> $stored line id => item_id */
            $stored = $po === null
                ? collect()
                : $po->items()->get(['id', 'item_id'])
                    ->mapWithKeys(fn ($row) => [(int) $row->id => $row->item_id === null ? null : (int) $row->item_id]);

            $wanted = collect($lines)
                ->map(fn ($line) => is_array($line) ? ($line['item_id'] ?? null) : null)
                ->filter(fn ($id) => $id !== null && $id !== '')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            $active = $wanted->isEmpty()
                ? []
                : Item::query()->whereIn('id', $wanted)->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();

            foreach ($lines as $i => $line) {
                if (! is_array($line)) {
                    continue;
                }

                $lineId = $line['id'] ?? null;
                $itemId = isset($line['item_id']) && $line['item_id'] !== '' ? (int) $line['item_id'] : null;

                if ($lineId !== null && $lineId !== '') {
                    if (! $stored->has((int) $lineId)) {
                        $v->errors()->add("items.$i.id", 'This line does not belong to this purchase order. Reload the page.');

                        continue;
                    }

                    $storedItem = $stored->get((int) $lineId);

                    if ($itemId === null) {
                        if ($storedItem !== null) {
                            $v->errors()->add("items.$i.item_id", 'Choose a catalog item for this line.');
                        }

                        continue; // an old line that was never in the catalog stays as it is
                    }

                    if ($itemId === $storedItem) {
                        continue; // unchanged, even if the item is inactive now
                    }
                } elseif ($itemId === null) {
                    $v->errors()->add("items.$i.item_id", 'Choose an item from the catalog.');

                    continue;
                }

                if (! in_array($itemId, $active, true)) {
                    $v->errors()->add("items.$i.item_id", 'Choose an active catalog item.');
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'po_number.regex' => 'Use letters, digits and - / . _ only, with no spaces.',
            'po_number.unique' => 'A purchase order with this number already exists.',
            'supplier_id.exists' => 'Choose an active supplier.',
            'items.required' => 'Add at least one item.',
            'items.min' => 'Add at least one item.',
            'schedule_dates.*.distinct' => 'This date is repeated.',
            'items.*.unit_price.decimal' => 'Use at most 2 decimal places.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'po_number' => 'PO number',
            'supplier_id' => 'supplier',
            'po_date' => 'PO date',
            'emailed_date' => 'emailed date',
            'delivery_term_days' => 'delivery term',
            'schedule_dates.*' => 'schedule date',
            'items.*.description' => 'description',
            'items.*.unit_price' => 'unit price',
            'items.*.quantity_ordered' => 'quantity',
        ];
    }

    private function currentPo(): ?PurchaseOrder
    {
        $po = $this->route('purchaseOrder');

        return $po instanceof PurchaseOrder ? $po : null;
    }
}