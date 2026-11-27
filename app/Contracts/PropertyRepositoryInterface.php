<?php

namespace App\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Reads of delivered lines for the property pages (step 6.2). Writing is done by the Services only.
 *
 * The state of a line (pending decision, pending documents, done) is COMPUTED from its decisions and documents, never
 * stored. Tabs:
 *   pending    an eligible line with no active decision, or an active decision that still lacks a document
 *   done       an eligible line with a decision and every document it calls for
 *   attention  the line or its delivery was voided AFTER a decision exists: staff void the decision and documents by hand
 *   voided     voided documents (their numbers stay on record)
 */
interface PropertyRepositoryInterface
{
    public const TAB_PENDING = 'pending';

    public const TAB_DONE = 'done';

    public const TAB_ATTENTION = 'attention';

    public const TAB_VOIDED = 'voided';

    public const TABS = [self::TAB_PENDING, self::TAB_DONE, self::TAB_ATTENTION, self::TAB_VOIDED];

    /** Delivered lines for the pending, done and attention tabs. Eager-loads what the rows show. */
    public function paginateItems(string $tab, string $search, int $perPage): LengthAwarePaginator;

    /** Voided documents, newest first. */
    public function paginateVoidedDocuments(string $search, int $perPage): LengthAwarePaginator;

    public function count(string $tab): int;

    /** Every decision of one line, newest first, with its documents and the people who acted. */
    public function decisionsFor(int $deliveryItemId): Collection;
}
