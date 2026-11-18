<?php

namespace App\Models;

use App\Enums\PoDocumentStatus;
use App\Enums\PoDocumentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $purchase_order_id
 * @property int|null $delivery_id
 * @property PoDocumentType $type
 * @property string $original_name
 * @property string $stored_path
 * @property string|null $original_path
 * @property string $mime
 * @property int $size_bytes
 * @property int $uploaded_by
 * @property int $sort_order
 * @property PoDocumentStatus $status
 * @property string|null $void_reason
 * @property int|null $page_count
 * @property int|null $voided_by
 * @property Carbon|null $voided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PurchaseOrder $purchaseOrder
 * @property-read User $uploader
 *
 * Not mass-assignable on purpose: status, void_reason, voided_by, voided_at (only
 * PoDocumentService::void() and replace() set them, with a reason) and page_count (only the
 * consolidated PDF builder sets it).
 *
 * Files live on the PRIVATE disk (storage/app/private). Never expose
 * stored_path to the browser; files are streamed through authorized routes.
 */
#[Fillable([
    'purchase_order_id',
    'delivery_id',
    'type',
    'original_name',
    'stored_path',
    'original_path',
    'mime',
    'size_bytes',
    'uploaded_by',
    'sort_order',
])]
class PoDocument extends Model
{
    /** Defaults for a new, unsaved model (the database has the same one). */
    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PoDocumentType::class,
            'status' => PoDocumentStatus::class,
            'size_bytes' => 'integer',
            'sort_order' => 'integer',
            'page_count' => 'integer',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}