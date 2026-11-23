<?php

namespace App\Http\Controllers\Admin;

use App\Enums\IarSeriesType;
use App\Http\Requests\Admin\SetIarCounterRequest;
use App\Models\IarNumber;
use App\Services\IarNumberService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Step 5.1: the Admin screen for IAR number series and voided numbers. Rules live in IarNumberService. */
class IarNumberController
{
    public function index(Request $request, IarNumberService $service): Response
    {
        Gate::authorize('viewAny', IarNumber::class);

        $search = trim((string) $request->query('search', ''));

        return Inertia::render('admin/iar-numbers/index', [
            'series' => $service->seriesOverview(),
            'voided' => $service->voidedNumbers($search),
            'filters' => ['search' => $search],
            'currentYear' => $service->currentYear(),
        ]);
    }

    public function setCounter(SetIarCounterRequest $request, IarNumberService $service): RedirectResponse
    {
        $changed = $service->setCounter(
            IarSeriesType::from($request->validated('type')),
            (int) $request->validated('year'),
            (int) $request->validated('next_number'),
        );

        return redirect()
            ->route('admin.iar-numbers.index')
            ->with('success', $changed ? 'Counter updated.' : 'The counter already had that value.');
    }
}