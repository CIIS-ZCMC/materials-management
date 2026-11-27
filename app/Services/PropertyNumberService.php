<?php

namespace App\Services;

use App\Enums\PropertyDocumentStatus;
use App\Enums\PropertyDocumentType;
use App\Models\PropertyDocument;
use App\Models\PropertySeries;
use App\Support\Audit;
use App\Support\DisplayTime;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PAR / Sub-PAR / ICS numbering (step 6.2). Same idea as IarNumberService (step 5.1):
 *
 *   suggest()      the next free number of a type and year. Does NOT use it up.
 *   claim()        checks a number (the suggested one or one typed by staff), advances the counter and returns where to
 *                  save it. PropertyDocumentService calls it INSIDE its own transaction and saves the document row
 *                  right after; the unique index on property_documents.number is the last defence.
 *   setCounter()   Admin: set where a series starts counting.
 *
 * A voided document keeps its row, so its number is never suggested or claimed again. Every method that changes data
 * locks the series row first. Audit entries go to log "property".
 */
class PropertyNumberService
{
    private const MAX_SEQUENCE = 999999;

    /** @return array{type: string, year: int, sequence: int, number: string} */
    public function suggest(PropertyDocumentType $type, ?int $year = null): array
    {
        $year = $this->checkedYear($year ?? $this->currentYear());

        return DB::transaction(function () use ($type, $year): array {
            $series = $this->lockedSeries($type, $year);
            $sequence = $series->next_number;

            // Defensive: skip anything already recorded (issued or void) in this series.
            while (PropertyDocument::query()->where('property_series_id', $series->id)->where('sequence', $sequence)->exists()) {
                $sequence++;
            }

            return ['type' => $type->value, 'year' => $year, 'sequence' => $sequence, 'number' => $type->format($year, $sequence)];
        });
    }

    /**
     * Call inside a transaction. Throws a ValidationException on $field when the number is malformed or already recorded.
     *
     * @return array{series: PropertySeries, sequence: int, number: string}
     */
    public function claim(PropertyDocumentType $type, int $year, string $number, string $field = 'number'): array
    {
        $year = $this->checkedYear($year);
        $number = strtoupper(trim($number));
        $sequence = $this->parse($type, $year, $number, $field);

        $series = $this->lockedSeries($type, $year);

        $existing = PropertyDocument::query()->where('number', $number)->first();

        if ($existing !== null) {
            throw $this->duplicate($existing, $field);
        }

        // Move the counter past the number that was just taken (never backwards).
        if ($sequence >= $series->next_number) {
            $series->forceFill(['next_number' => $sequence + 1])->save();
        }

        return ['series' => $series, 'sequence' => $sequence, 'number' => $number];
    }

    public function duplicateFor(string $number, string $field): ValidationException
    {
        return $this->duplicate(PropertyDocument::query()->where('number', $number)->first(), $field, $number);
    }

    public function setCounter(PropertyDocumentType $type, int $year, int $next): bool
    {
        $year = $this->checkedYear($year);

        if ($next < 1 || $next > self::MAX_SEQUENCE) {
            throw ValidationException::withMessages([
                'next_number' => 'The counter must be a whole number from 1 to '.self::MAX_SEQUENCE.'.',
            ]);
        }

        return DB::transaction(function () use ($type, $year, $next): bool {
            $series = $this->lockedSeries($type, $year);

            $highest = (int) PropertyDocument::query()->where('property_series_id', $series->id)->max('sequence');

            if ($next <= $highest) {
                throw ValidationException::withMessages([
                    'next_number' => "The counter must be above {$highest}, the highest number already recorded for {$series->auditLabel()}.",
                ]);
            }

            if ($next === $series->next_number) {
                return false;
            }

            $old = $series->next_number;
            $series->forceFill(['next_number' => $next])->save();

            Audit::log(
                event: 'property_counter_set',
                subject: $series,
                description: 'Property counter set: '.$series->auditLabel().' now starts at '.$type->format($year, $next),
                old: ['next_number' => $old],
                new: ['next_number' => $next],
                log: 'property',
            );

            return true;
        });
    }

    /**
     * One row per series that exists, plus the current year's three series if they do not exist yet (shown as "Not started").
     *
     * @return list<array<string, mixed>>
     */
    public function seriesOverview(): array
    {
        $rows = PropertySeries::query()
            ->withCount([
                'documents as used_count' => fn ($q) => $q->where('status', PropertyDocumentStatus::Issued->value),
                'documents as void_count' => fn ($q) => $q->where('status', PropertyDocumentStatus::Voided->value),
            ])
            ->withMax('documents', 'sequence')
            ->get();

        $list = [];

        foreach ($rows as $s) {
            $list[] = $this->seriesRow($s->type, $s->year, $s->next_number, $s->documents_max_sequence, (int) $s->used_count, (int) $s->void_count, true);
        }

        $year = $this->currentYear();

        foreach (PropertyDocumentType::cases() as $type) {
            if (! $rows->contains(fn (PropertySeries $s) => $s->type === $type && $s->year === $year)) {
                $list[] = $this->seriesRow($type, $year, 1, null, 0, 0, false);
            }
        }

        usort($list, fn (array $a, array $b) => $b['year'] <=> $a['year'] ?: $a['order'] <=> $b['order']);

        return $list;
    }

    /** Voided documents (numbers that can never be reused), newest first, optional search on the number or the reason. */
    public function voidedNumbers(string $search = '', int $perPage = 15): LengthAwarePaginator
    {
        $query = PropertyDocument::query()
            ->with(['series', 'voider:id,name', 'decision:id,delivery_item_id'])
            ->where('status', PropertyDocumentStatus::Voided->value)
            ->orderByDesc('voided_at')
            ->orderByDesc('id');

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(fn ($q) => $q->where('number', 'like', $like)->orWhere('void_reason', 'like', $like));
        }

        return $query->paginate($perPage)->withQueryString()->through(fn (PropertyDocument $d) => [
            'id' => $d->id,
            'number' => $d->number,
            'series_label' => $d->series?->auditLabel(),
            'void_reason' => $d->void_reason,
            'voided_by' => $d->voider?->name,
            'voided_at' => $d->voided_at ? DisplayTime::format($d->voided_at) : null,
            'delivery_item_id' => $d->decision?->delivery_item_id,
        ]);
    }

    public function currentYear(): int
    {
        return (int) now()->setTimezone((string) config('mms.display_timezone', 'Asia/Manila'))->format('Y');
    }

    // ---- internals -------------------------------------------------------------------------------

    /** Finds the series row with a lock, creating it (next number 1) the first time. Call inside a transaction. */
    private function lockedSeries(PropertyDocumentType $type, int $year): PropertySeries
    {
        $find = fn () => PropertySeries::query()
            ->where('type', $type->value)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        $series = $find();

        if ($series === null) {
            try {
                $new = new PropertySeries;
                $new->forceFill(['type' => $type, 'year' => $year, 'next_number' => 1])->save();
            } catch (UniqueConstraintViolationException) {
                // Someone else created it a moment ago; read theirs below.
            }

            $series = $find();
        }

        return $series;
    }

    /** Returns the sequence of a well-formed number, or throws. The number must be in its one canonical spelling. */
    private function parse(PropertyDocumentType $type, int $year, string $number, string $field): int
    {
        $example = $type->format($year, 1);
        $message = "Write the {$type->label()} number like {$example} (the {$year} series).";

        $pattern = '/^'.preg_quote($type->prefix(), '/').'(\d{2})-(\d{4,6})$/';

        if (! preg_match($pattern, $number, $m)) {
            throw ValidationException::withMessages([$field => $message]);
        }

        $sequence = (int) $m[2];

        // Rebuilding the number rejects spellings like PAR-26-00012 that would dodge the unique index.
        if ((int) $m[1] !== $year % 100 || $sequence < 1 || $type->format($year, $sequence) !== $number) {
            throw ValidationException::withMessages([$field => $message]);
        }

        return $sequence;
    }

    private function duplicate(?PropertyDocument $existing, string $field, ?string $number = null): ValidationException
    {
        $number = $existing?->number ?? $number ?? 'This number';

        $text = $existing?->status === PropertyDocumentStatus::Voided
            ? "{$number} was voided earlier and can never be used again."
            : "{$number} is already in use.";

        return ValidationException::withMessages([$field => $text]);
    }

    private function checkedYear(int $year): int
    {
        if ($year < 2000 || $year > 2099) {
            throw ValidationException::withMessages(['year' => 'The year must be between 2000 and 2099.']);
        }

        return $year;
    }

    /** @return array<string, mixed> */
    private function seriesRow(PropertyDocumentType $type, int $year, int $next, ?int $highest, int $used, int $void, bool $exists): array
    {
        return [
            'type' => $type->value,
            'type_label' => $type->label(),
            'order' => array_search($type, PropertyDocumentType::cases(), true),
            'year' => $year,
            'next_number' => $next,
            'next_formatted' => $type->format($year, $next),
            'highest' => $highest,
            'used' => $used,
            'void' => $void,
            'exists' => $exists,
        ];
    }
}
