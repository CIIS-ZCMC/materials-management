<?php

namespace Database\Seeders;

use App\Enums\IarSeriesType;
use App\Models\IarSeries;
use Illuminate\Database\Seeder;

/**
 * DEV ONLY. Counters for 2026 taken from the highest IAR number of each prefix in the sanitized
 * PO-26B sample (26-7015, C26-7012, D26-7010). The sample's digits were replaced, so these are fake.
 * In production the Admin sets the real counters on the IAR numbers screen.
 * Safe to run twice: an existing series is left alone.
 */
class IarSeriesSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('IarSeriesSeeder is for development only. Set the counters on the IAR numbers screen.');

            return;
        }

        $counters = [
            [IarSeriesType::Regular, 2026, 7016],
            [IarSeriesType::Contract, 2026, 7013],
            [IarSeriesType::Donation, 2026, 7011],
        ];

        foreach ($counters as [$type, $year, $next]) {
            $exists = IarSeries::query()->where('type', $type->value)->where('year', $year)->exists();

            if ($exists) {
                continue;
            }

            $series = new IarSeries;
            $series->forceFill(['type' => $type, 'year' => $year, 'next_number' => $next])->save();
        }
    }
}