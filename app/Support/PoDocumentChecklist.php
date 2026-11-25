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
 * stage "po"        types that may be chosen in the PO upload form (the PO Bundle and its extras).
 * stage "delivery"  DR, OR, IAR, Notice of Delivery, Advance Notice Letter: chosen in the documents panel of a
 *                   DELIVERY (step 5.3), never in the PO upload form. Required ones are the completeness gate.
 * stage "exception" Flow A documents: not selectable anywhere yet.
 * A type may list several stages (stage => ['po', 'delivery']). entries() keeps 'stage' = the first one (old
 * callers) and adds 'stages' = all of them.
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
     * @return list<array{key: string, label: string, required: bool, stage: string, stages: list<string>, sort: int}>
     */
    public static function entries(): array
    {
        $entries = [];

        foreach ((array) config('mms.documents.types', []) as $row) {
            $key = is_array($row) ? (string) ($row['key'] ?? '') : '';

            if (PoDocumentType::tryFrom($key) === null) {
                continue;
            }

            $stages = array_values(array_unique(array_map('strval', (array) ($row['stage'] ?? self::STAGE_PO))));
            $stages = $stages === [] ? [self::STAGE_PO] : $stages;

            $entries[] = [
                'key' => $key,
                'label' => trim((string) ($row['label'] ?? '')) !== '' ? trim((string) $row['label']) : ucwords(str_replace('_', ' ', $key)),
                'required' => (bool) ($row['required'] ?? false),
                'stage' => $stages[0],
                'stages' => $stages,
                'sort' => (int) ($row['sort'] ?? 0),
            ];
        }

        usort($entries, fn (array $a, array $b) => [$a['sort'], $a['key']] <=> [$b['sort'], $b['key']]);

        return $entries;
    }

    /** @return array{key: string, label: string, required: bool, stage: string, stages: list<string>, sort: int}|null */
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
            array_filter(self::entries(), fn (array $entry) => in_array(self::STAGE_PO, $entry['stages'], true)),
        ));
    }

    /**
     * The types a DELIVERY accepts (step 5.3), in the configured order.
     *
     * @return list<array{key: string, label: string, required: bool, sort: int}>
     */
    public static function deliveryTypes(): array
    {
        return array_values(array_map(
            fn (array $entry) => ['key' => $entry['key'], 'label' => $entry['label'], 'required' => $entry['required'], 'sort' => $entry['sort']],
            array_filter(self::entries(), fn (array $entry) => in_array(self::STAGE_DELIVERY, $entry['stages'], true)),
        ));
    }

    public static function isDeliveryType(string $key): bool
    {
        foreach (self::deliveryTypes() as $type) {
            if ($type['key'] === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * The REQUIRED delivery types (key => label). This is the one list the completeness gate reads, so
     * changing config/mms.php is enough to change what a delivery needs.
     *
     * @return array<string, string>
     */
    public static function deliveryRequired(): array
    {
        $required = [];

        foreach (self::deliveryTypes() as $type) {
            if ($type['required']) {
                $required[$type['key']] = $type['label'];
            }
        }

        return $required;
    }

    /** Place of a type inside one delivery in the combined PDF: its "sort" number (unknown types last). */
    public static function deliverySort(string $key): int
    {
        return self::entry($key)['sort'] ?? PHP_INT_MAX;
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
     * that has active documents, and "x of y required types uploaded".
     * Only REQUIRED entries of stage "po" count towards the indicator. Files that belong to a delivery
     * are not counted here (step 5.3).
     *
     * @return array{is_sample: bool, required_total: int, required_done: int, complete: bool, missing: list<string>, items: list<array{key: string, label: string, required: bool, count: int, selectable: bool}>}
     */
    public static function progress(PurchaseOrder $po): array
    {
        /** @var array<string, int> $counts */
        $counts = PoDocument::query()
            ->where('purchase_order_id', $po->getKey())
            ->whereNull('delivery_id') // delivery files have their own checklist (step 5.3)
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
            $inList = in_array(self::STAGE_PO, $entry['stages'], true);

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