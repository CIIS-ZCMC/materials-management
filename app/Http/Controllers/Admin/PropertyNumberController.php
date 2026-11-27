<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PropertyDocumentType;
use App\Http\Requests\Admin\SetPropertyCounterRequest;
use App\Models\PropertySeries;
use App\Services\PropertyNumberService;
use App\Support\PropertyDocumentRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Step 6.2: the Admin screen for PAR / Sub-PAR / ICS number counters and voided numbers. Rules live in PropertyNumberService. */
class PropertyNumberController
{
    public function index(Request $request, PropertyNumberService $service): Response
    {
        Gate::authorize('viewAny', PropertySeries::class);

        $search = trim((string) $request->query('search', ''));

        return Inertia::render('admin/property-numbers/index', [
            'series' => $service->seriesOverview(),
            'voided' => $service->voidedNumbers($search),
            'filters' => ['search' => $search],
            'currentYear' => $service->currentYear(),
            'provisional' => PropertyDocumentRule::isProvisional(),
        ]);
    }

    public function setCounter(SetPropertyCounterRequest $request, PropertyNumberService $service): RedirectResponse
    {
        $changed = $service->setCounter(
            PropertyDocumentType::from($request->validated('type')),
            (int) $request->validated('year'),
            (int) $request->validated('next_number'),
        );

        return redirect()
            ->route('admin.property-numbers.index')
            ->with('success', $changed ? 'Counter updated.' : 'The counter already had that value.');
    }
}
