<?php

namespace App\Models;

use App\Enums\DeliverySignatoryRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A person on one delivery (inspector or end-user), as a SNAPSHOT: name and position are copied from
 * the personnel directory when the person is chosen, so the record stays readable if UMIS changes
 * later. person_id is the directory's id (a string, because a UMIS id may not be a number).
 *
 * @property int $id
 * @property int $delivery_id
 * @property DeliverySignatoryRole $role
 * @property string $person_id
 * @property string $employee_no
 * @property string $name
 * @property string|null $position
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Delivery $delivery
 */
#[Fillable(['delivery_id', 'role', 'person_id', 'employee_no', 'name', 'position', 'sort_order'])]
class DeliverySignatory extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => DeliverySignatoryRole::class,
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Delivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }
}
