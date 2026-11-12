<?php

namespace App\Support;

use App\Enums\Transitionable;
use App\Exceptions\InvalidStatusTransition;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The one way to change a stored status (PO, delivery, DV, replacement).
 *
 *   StatusTransition::apply($po, 'status', PurchaseOrderStatus::Verified);
 *   StatusTransition::apply($dv, 'status', DvStatus::Returned, reason: 'Missing OR');
 *
 * It, in one database transaction:
 *   1. re-reads the current status with a row lock (two people cannot move
 *      the same record at once),
 *   2. refuses a change the enum does not allow (InvalidStatusTransition),
 *   3. refuses a change that needs a reason when none was given,
 *   4. saves the new status,
 *   5. writes the audit entry through Audit::statusChanged() with the enum
 *      labels (for example "Uploaded to Verified"). If the audit write fails,
 *      the status change is rolled back too.
 *
 * Call it from a Service with a freshly loaded model. It does NOT check
 * permissions (use the Policy) and does not know other business rules (for
 * example "DR, OR and IAR must exist"); the Service checks those first.
 */
final class StatusTransition
{
    /** True when $from may move to $to (same enum, and listed in allowedNext()). */
    public static function can(Transitionable $from, Transitionable $to): bool
    {
        return $from::class === $to::class
            && in_array($to, $from->allowedNext(), true);
    }

    /**
     * @param  array<string, mixed>  $extra  more properties for the audit entry
     * @return bool true if the status changed; false if it already had that status (nothing saved, nothing logged)
     *
     * @throws InvalidStatusTransition
     */
    public static function apply(
        Model $model,
        string $column,
        Transitionable&BackedEnum $to,
        ?string $reason = null,
        array $extra = [],
        string $log = 'default',
    ): bool {
        $reason = filled($reason) ? trim((string) $reason) : null;
        $enum = $to::class;

        return DB::transaction(function () use ($model, $column, $to, $reason, $extra, $log, $enum): bool {
            $stored = $model->newQuery()
                ->whereKey($model->getKey())
                ->lockForUpdate()
                ->toBase()
                ->value($column);

            if ($stored === null) {
                throw new LogicException('Save the record before changing its status.');
            }

            $from = $enum::tryFrom((string) $stored);

            if ($from === null) {
                throw new LogicException("Unknown value \"{$stored}\" in column \"{$column}\" for {$enum}.");
            }

            // The model in memory must still match the database.
            $loaded = $model->getRawOriginal($column);
            $loaded = $loaded instanceof BackedEnum ? $loaded->value : (string) $loaded;

            if ($loaded !== (string) $stored) {
                throw InvalidStatusTransition::changedMeanwhile($from);
            }

            if ($from === $to) {
                return false;
            }

            if (! self::can($from, $to)) {
                throw InvalidStatusTransition::notAllowed($from, $to);
            }

            if ($reason === null && $from->requiresReasonFor($to)) {
                throw InvalidStatusTransition::reasonRequired($from, $to);
            }

            $model->forceFill([$column => $to->value])->save();

            Audit::statusChanged($model, $from->label(), $to->label(), $reason, $extra, $log);

            return true;
        });
    }
}