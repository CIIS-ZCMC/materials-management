<?php

namespace App\Services;

use App\Data\IarSuggestion;
use App\Enums\IarNumberStatus;
use App\Enums\IarSeriesType;
use App\Models\IarNumber;
use App\Models\IarSeries;
use App\Support\Audit;
use App\Support\DisplayTime;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * IAR numbering (step 5.1, CONTEXT.md decision 46).
 *
 *   suggest()      the next free number of a series and year. Does NOT use it up.
 *   reserve()      saves a number (the suggested one or one typed by staff). Step 5.2 calls this
 *                  inside the delivery save. The database rejects a duplicate.
 *   void()         cancels a number with a reason. A voided number is never suggested or reserved again.
 *   setCounter()   Admin: set where a series starts counting.
 *
 * Every method that changes data runs in one transaction and locks the series row first, so two people
 * saving at the same moment cannot get the same number. Audit entries go to log "iar".
 */
class IarNumberService
{
    private const MAX_SEQUENCE = 999999;

    public function suggest(IarSeriesType $type, ?int $year = null): IarSuggestion
    {
        $year = $this->checkedYear($year ?? $this->currentYear());

        return DB::transaction(function () use ($type, $year): IarSuggestion {
            $series = $this->lockedSeries($type, $year);

            $sequence = $series->next_number;

            // Defensive: skip anything already recorded (used or void) in this series.
            while (IarNumber::query()->where('iar_series_id', $series->id)->where('sequence', $sequence)->exists()) {
                $sequence++;
            }

            return new IarSuggestion($type, $year, $sequence, $type->format($year, $sequence));
        });
    }

    /**
     * @throws ValidationException (key "iar_number") when the number is malformed or already recorded
     */
    public function reserve(IarSeriesType $type, int $year, string $number, ?int $deliveryId = null): IarNumber
    {
        $year = $this->checkedYear($year);
        $number = strtoupper(trim($number));
        $sequence = $this->parse($type, $year, $number);

        return DB::transaction(function () use ($type, $year, $number, $sequence, $deliveryId): IarNumber {
            $series = $this->lockedSeries($type, $year);

            $existing = IarNumber::query()->where('number', $number)->first();

            if ($existing !== null) {
                throw $this->duplicate($existing);
            }

            $row = new IarNumber;
            $row->forceFill([
                'number' => $number,
                'iar_series_id' => $series->id,
                'sequence' => $sequence,
                'status' => IarNumberStatus::Used,
                'delivery_id' => $deliveryId,
                'reserved_by' => Auth::id(),
                'reserved_at' => now(),
            ]);

            try {
                $row->save();
            } catch (UniqueConstraintViolationException) {
                // The unique index is the last line of defence (another request, or a number typed twice).
                throw $this->duplicate(IarNumber::query()->where('number', $number)->first());
            }

            // A number typed above the counter pushes the counter past it, so later suggestions cannot collide.
            if ($sequence >= $series->next_number) {
                $series->forceFill(['next_number' => $sequence + 1])->save();
            }

            Audit::log(
                event: 'iar_number_reserved',
                subject: $row,
                description: 'IAR number reserved: '.$number,
                new: ['iar_number' => $number, 'series' => $series->auditLabel()],
                extra: array_filter(['delivery_id' => $deliveryId]),
                log: 'iar',
            );

            return $row;
        });
    }

    /**
     * @throws ValidationException (key "reason" or "iar_number")
     */
    public function void(IarNumber $number, string $reason): IarNumber
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Give a reason of 5 to 500 characters.']);
        }

        return DB::transaction(function () use ($number, $reason): IarNumber {
            $row = IarNumber::query()->whereKey($number->getKey())->lockForUpdate()->firstOrFail();

            if ($row->status === IarNumberStatus::Void) {
                throw ValidationException::withMessages([
                    'iar_number' => "IAR number {$row->number} is already voided.",
                ]);
            }

            $row->forceFill([
                'status' => IarNumberStatus::Void,
                'void_reason' => $reason,
                'voided_by' => Auth::id(),
                'voided_at' => now(),
            ])->save();

            Audit::log(
                event: 'iar_number_voided',
                subject: $row,
                description: 'IAR number voided: '.$row->number,
                old: ['status' => IarNumberStatus::Used->label()],
                new: ['status' => IarNumberStatus::Void->label()],
                reason: $reason,
                log: 'iar',
            );

            return $row;
        });
    }

    /**
     * Admin: set the number a series starts from. It must be above the highest number already recorded
     * (used or void) in that series and year, otherwise the next suggestion could repeat a number.
     *
     * @return bool true if the counter changed
     *
     * @throws ValidationException (key "next_number")
     */
    public function setCounter(IarSeriesType $type, int $year, int $next): bool
    {
        $year = $this->checkedYear($year);

        if ($next < 1 || $next > self::MAX_SEQUENCE) {
            throw ValidationException::withMessages([
                'next_number' => 'The counter must be a whole number from 1 to '.self::MAX_SEQUENCE.'.',
            ]);
        }

        return DB::transaction(function () use ($type, $year, $next): bool {
            $series = $this->lockedSeries($type, $year);

            $highest = (int) IarNumber::query()->where('iar_series_id', $series->id)->max('sequence');

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
                event: 'iar_counter_set',
                subject: $series,
                description: 'IAR counter set: '.$series->auditLabel().' now starts at '.$type->format($year, $next),
                old: ['next_number' => $old],
                new: ['next_number' => $next],
                log: 'iar',
            );

            return true;
        });
    }

    /**
     * One row per series that exists, plus the current year's three series if they do not exist yet
     * (shown as "Not started", next number 1).
     *
     * @return list<array<string, mixed>>
     */
    public function seriesOverview(): array
    {
        $rows = IarSeries::query()
            ->withCount([
                'numbers as used_count' => fn ($q) => $q->where('status', IarNumberStatus::Used->value),
                'numbers as void_count' => fn ($q) => $q->where('status', IarNumberStatus::Void->value),
            ])
            ->withMax('numbers', 'sequence')
            ->get();

        $list = [];

        foreach ($rows as $s) {
            $list[] = $this->seriesRow($s->type, $s->year, $s->next_number, $s->numbers_max_sequence, (int) $s->used_count, (int) $s->void_count, true);
        }

        $year = $this->currentYear();

        foreach (IarSeriesType::cases() as $type) {
            if (! $rows->contains(fn (IarSeries $s) => $s->type === $type && $s->year === $year)) {
                $list[] = $this->seriesRow($type, $year, 1, null, 0, 0, false);
            }
        }

        usort($list, fn (array $a, array $b) => $b['year'] <=> $a['year'] ?: $a['order'] <=> $b['order']);

        return $list;
    }

    /** Voided numbers, newest first, optional search on the number or the reason. */
    public function voidedNumbers(string $search = '', int $perPage = 15): LengthAwarePaginator
    {
        $query = IarNumber::query()
            ->with(['series', 'voidedBy:id,name'])
            ->where('status', IarNumberStatus::Void->value)
            ->orderByDesc('voided_at')
            ->orderByDesc('id');

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(fn ($q) => $q->where('number', 'like', $like)->orWhere('void_reason', 'like', $like));
        }

        return $query->paginate($perPage)->withQueryString()->through(fn (IarNumber $n) => [
            'id' => $n->id,
            'number' => $n->number,
            'series_label' => $n->series?->auditLabel(),
            'void_reason' => $n->void_reason,
            'voided_by' => $n->voidedBy?->name,
            'voided_at' => $n->voided_at ? DisplayTime::format($n->voided_at) : null,
            'delivery_id' => $n->delivery_id,
        ]);
    }

    public function currentYear(): int
    {
        return (int) now()->setTimezone((string) config('mms.display_timezone', 'Asia/Manila'))->format('Y');
    }

    // ---- internals -------------------------------------------------------------------------------

    /** Finds the series row with a lock, creating it (next number 1) the first time. Call inside a transaction. */
    private function lockedSeries(IarSeriesType $type, int $year): IarSeries
    {
        $find = fn () => IarSeries::query()
            ->where('type', $type->value)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        $series = $find();

        if ($series === null) {
            try {
                $new = new IarSeries;
                $new->forceFill(['type' => $type, 'year' => $year, 'next_number' => 1])->save();
            } catch (UniqueConstraintViolationException) {
                // Someone else created it a moment ago; read theirs below.
            }

            $series = $find();
        }

        return $series;
    }

    /** Returns the sequence of a well-formed number, or throws. The number must be in its one canonical spelling. */
    private function parse(IarSeriesType $type, int $year, string $number): int
    {
        $example = $type->format($year, 1);
        $message = "Write the IAR number like {$example} for the {$type->label()} series of {$year}.";

        $pattern = '/^'.preg_quote($type->prefix(), '/').'(\d{2})-(\d{4,6})$/';

        if (! preg_match($pattern, $number, $m)) {
            throw ValidationException::withMessages(['iar_number' => $message]);
        }

        $sequence = (int) $m[2];

        // Rebuilding the number rejects spellings like 26-01398 that would dodge the unique index.
        if ((int) $m[1] !== $year % 100 || $sequence < 1 || $type->format($year, $sequence) !== $number) {
            throw ValidationException::withMessages(['iar_number' => $message]);
        }

        return $sequence;
    }

    private function duplicate(?IarNumber $existing): ValidationException
    {
        $number = $existing?->number ?? 'This IAR number';

        $text = $existing?->status === IarNumberStatus::Void
            ? "IAR number {$number} was voided earlier and can never be used again."
            : "IAR number {$number} is already in use.";

        return ValidationException::withMessages(['iar_number' => $text]);
    }

    private function checkedYear(int $year): int
    {
        if ($year < 2000 || $year > 2099) {
            throw ValidationException::withMessages(['year' => 'The year must be between 2000 and 2099.']);
        }

        return $year;
    }

    /** @return array<string, mixed> */
    private function seriesRow(IarSeriesType $type, int $year, int $next, ?int $highest, int $used, int $void, bool $exists): array
    {
        return [
            'type' => $type->value,
            'type_label' => $type->label(),
            'order' => (int) array_search($type, IarSeriesType::cases(), true),
            'year' => $year,
            'next_number' => $next,
            'next_formatted' => $type->format($year, $next),
            'last_recorded' => $highest !== null ? $type->format($year, $highest) : null,
            'used_count' => $used,
            'void_count' => $void,
            'exists' => $exists,
        ];
    }
}