<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveSupplierRequest;
use App\Models\Supplier;
use App\Services\SupplierService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    public function __construct(private readonly SupplierService $service) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Supplier::class);

        $search = trim((string) $request->query('search', ''));
        $paginator = $this->service->paginate(['search' => $search]);

        return Inertia::render('suppliers/index', [
            'suppliers' => [
                'data' => $paginator->getCollection()
                    ->map(fn (Supplier $supplier) => $this->row($supplier))
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
            'canManage' => $request->user()->can('create', Supplier::class),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Supplier::class);

        return Inertia::render('suppliers/create');
    }

    public function store(SaveSupplierRequest $request): RedirectResponse
    {
        $this->service->create($request->validated());

        return to_route('suppliers.index')->with('success', 'Supplier created.');
    }

    public function edit(Supplier $supplier): Response
    {
        Gate::authorize('update', $supplier);

        return Inertia::render('suppliers/edit', [
            'supplier' => $this->row($supplier),
        ]);
    }

    public function update(SaveSupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        $this->service->update($supplier, $request->validated());

        return to_route('suppliers.index')->with('success', 'Supplier updated.');
    }

    public function toggleActive(Request $request, Supplier $supplier): RedirectResponse
    {
        Gate::authorize('toggleActive', $supplier);

        $active = $request->boolean('active');
        $this->service->setActive($supplier, $active);

        return back()->with('success', $active ? 'Supplier activated.' : 'Supplier deactivated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Supplier $supplier): array
    {
        return [
            'id' => $supplier->id,
            'name' => $supplier->name,
            'email' => $supplier->email,
            'contact_person' => $supplier->contact_person,
            'address' => $supplier->address,
            'is_active' => $supplier->is_active,
        ];
    }
}