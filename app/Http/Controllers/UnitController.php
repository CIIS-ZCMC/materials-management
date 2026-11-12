<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveUnitRequest;
use App\Models\Unit;
use App\Services\UnitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class UnitController extends Controller
{
    public function __construct(private readonly UnitService $service) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Unit::class);

        $search = trim((string) $request->query('search', ''));
        $paginator = $this->service->paginate(['search' => $search]);

        return Inertia::render('catalog/units/index', [
            'units' => [
                'data' => $paginator->getCollection()
                    ->map(fn (Unit $unit) => $this->row($unit))
                    ->values(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'prev_page_url' => $paginator->previousPageUrl(),
                'next_page_url' => $paginator->nextPageUrl(),
            ],
            'filters' => ['search' => $search],
            'canManage' => $request->user()->can('create', Unit::class),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Unit::class);

        return Inertia::render('catalog/units/create');
    }

    public function store(SaveUnitRequest $request): RedirectResponse
    {
        $this->service->create($request->validated());

        return to_route('catalog.units.index')->with('success', 'Unit created.');
    }

    public function edit(Unit $unit): Response
    {
        Gate::authorize('update', $unit);

        return Inertia::render('catalog/units/edit', [
            'unit' => $this->row($unit),
        ]);
    }

    public function update(SaveUnitRequest $request, Unit $unit): RedirectResponse
    {
        $this->service->update($unit, $request->validated());

        return to_route('catalog.units.index')->with('success', 'Unit updated.');
    }

    public function toggleActive(Request $request, Unit $unit): RedirectResponse
    {
        Gate::authorize('toggleActive', $unit);

        $active = $request->boolean('active');
        $this->service->setActive($unit, $active);

        return back()->with('success', $active ? 'Unit activated.' : 'Unit deactivated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Unit $unit): array
    {
        return [
            'id' => $unit->id,
            'name' => $unit->name,
            'code' => $unit->code,
            'is_active' => $unit->is_active,
        ];
    }
}
