<?php

namespace App\Support;

use App\Enums\PoDocumentStatus;
use App\Enums\PoDocumentType;
use App\Models\PoDocument;
use App\Models\PurchaseOrder;

/**
 * The list of document types and the checklist indicator (step 4.2c).
 *
 * The list lives in ONE place: config/mms.php, key "documents.types" (key, label, required, stage, sort).
 * It is a SAMPLE until the MMS Office sends the real checklist (steps 5.3 and 7.1 review it).
 *
 * stage "po"        types that may be chosen in the upload form (the PO Bundle and its extras).
 * stage "delivery"  DR, OR, IAR, Notice of Delivery: added with a delivery in Phase 5, NOT selectable here.
 * stage "exception" Flow A and Flow B documents: not selectable here either.
 *
 * Every key must be a PoDocumentType value (the enum is the list of strings the database may hold);
 * an entry with an unknown key is ignored. The indicator is information only: it never blocks
 * "Submit to Reviewer". Void documents do not count.
 */
final class PoDocumentChecklist
{
    public const STAGE_PO = 'po';

    public const STAGE_DELIVERY = 'delivery';

    public const STAGE_EXCEPTION = 'exception';

    /**
     * Every configured entry, in "sort" order.
     *
     * @return list<array{key: string, label: string, required: bool, stage: string, sort: int}>
     */
    public static function entries(): array
    {
        $entries = [];

        foreach ((array) config('mms.documents.types', []) as $row) {
            $key = is_array($row) ? (string) ($row['key'] ?? '') : '';

            if (PoDocumentType::tryFrom($key) === null) {
                continue;
            }

            $entries[] = [
                'key' => $key,
                'label' => trim((string) ($row['label'] ?? '')) !== '' ? trim((string) $row['label']) : ucwords(str_replace('_', ' ', $key)),
                'required' => (bool) ($row['required'] ?? false),
                'stage' => (string) ($row['stage'] ?? self::STAGE_PO),
                'sort' => (int) ($row['sort'] ?? 0),
            ];
        }

        usort($entries, fn (array $a, array $b) => [$a['sort'], $a['key']] <=> [$b['sort'], $b['key']]);

        return $entries;
    }

    /** @return array{key: string, label: string, required: bool, stage: string, sort: int}|null */
    public static function entry(string $key): ?array
    {
        foreach (self::entries() as $entry) {
            if ($entry['key'] === $key) {
                return $entry;
            }
        }

        return null;
    }

    /** The configured label of a type, or null when the list does not mention it (the enum then supplies its own). */
    public static function label(string $key): ?string
    {
        return self::entry($key)['label'] ?? null;
    }

    /**
     * The types the upload form offers: stage "po" only.
     *
     * @return list<array{key: string, label: string}>
     */
    public static function selectable(): array
    {
        return array_values(array_map(
            fn (array $entry) => ['key' => $entry['key'], 'label' => $entry['label']],
            array_filter(self::entries(), fn (array $entry) => $entry['stage'] === self::STAGE_PO),
        ));
    }

    public static function isSelectable(string $key): bool
    {
        foreach (self::selectable() as $type) {
            if ($type['key'] === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the Documents tab shows above the page index: one item per "po" entry, plus any other type
     * that has active documents (for example IAR later), and "x of y required types uploaded".
     * Only REQUIRED entries of stage "po" count towards the indicator.
     *
     * @return array{is_sample: bool, required_total: int, required_done: int, complete: bool, missing: list<string>, items: list<array{key: string, label: string, required: bool, count: int, selectable: bool}>}
     */
    public static function progress(PurchaseOrder $po): array
    {
        /** @var array<string, int> $counts */
        $counts = PoDocument::query()
            ->where('purchase_order_id', $po->getKey())
            ->where('status', PoDocumentStatus::Active->value)
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type')
            ->map(fn ($total) => (int) $total)
            ->all();

        $items = [];
        $requiredTotal = 0;
        $requiredDone = 0;
        $missing = [];
        $listed = [];

        foreach (self::entries() as $entry) {
            $count = $counts[$entry['key']] ?? 0;
            $inList = $entry['stage'] === self::STAGE_PO;

            if (! $inList && $count === 0) {
                continue;
            }

            $listed[] = $entry['key'];
            $items[] = [
                'key' => $entry['key'],
                'label' => $entry['label'],
                'required' => $entry['required'],
                'count' => $count,
                'selectable' => $inList,
            ];

            if ($inList && $entry['required']) {
                $requiredTotal++;

                if ($count > 0) {
                    $requiredDone++;
                } else {
                    $missing[] = $entry['label'];
                }
            }
        }

        // A stored type the list does not mention (for example a type removed from the list later) still shows.
        foreach ($counts as $key => $count) {
            if ($count > 0 && ! in_array($key, $listed, true)) {
                $type = PoDocumentType::tryFrom($key);

                $items[] = [
                    'key' => $key,
                    'label' => $type?->label() ?? $key,
                    'required' => false,
                    'count' => $count,
                    'selectable' => false,
                ];
            }
        }

        return [
            'is_sample' => (bool) config('mms.documents.checklist_is_sample', true),
            'required_total' => $requiredTotal,
            'required_done' => $requiredDone,
            'complete' => $missing === [],
            'missing' => $missing,
            'items' => $items,
        ];
    }
}