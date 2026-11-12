<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveItemRequest;
use App\Models\Item;
use App\Services\ItemService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ItemController extends Controller
{
    public function __construct(private readonly ItemService $service) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Item::class);

        $search = trim((string) $request->query('search', ''));
        $paginator = $this->service->paginate(['search' => $search]);

        return Inertia::render('catalog/items/index', [
            'items' => [
                'data' => $paginator->getCollection()
                    ->map(fn (Item $item) => $this->row($item))
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
            'canManage' => $request->user()->can('create', Item::class),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Item::class);

        return Inertia::render('catalog/items/create', [
            'options' => $this->service->formOptions(),
        ]);
    }

    public function store(SaveItemRequest $request): RedirectResponse
    {
        $this->service->create($request->validated());

        return to_route('catalog.items.index')->with('success', 'Item created.');
    }

    public function edit(Item $item): Response
    {
        Gate::authorize('update', $item);

        return Inertia::render('catalog/items/edit', [
            'item' => $this->row($item),
            'options' => $this->service->formOptions($item),
        ]);
    }

    public function update(SaveItemRequest $request, Item $item): RedirectResponse
    {
        $this->service->update($item, $request->validated());

        return to_route('catalog.items.index')->with('success', 'Item updated.');
    }

    public function toggleActive(Request $request, Item $item): RedirectResponse
    {
        Gate::authorize('toggleActive', $item);

        $active = $request->boolean('active');
        $this->service->setActive($item, $active);

        return back()->with('success', $active ? 'Item activated.' : 'Item deactivated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Item $item): array
    {
        $item->loadMissing(['category:id,name,is_equipment', 'unit:id,name,code']);

        return [
            'id' => $item->id,
            'name' => $item->name,
            'category_id' => $item->category_id,
            'category' => $item->category?->name,
            'is_equipment' => (bool) $item->category?->is_equipment,
            'unit_id' => $item->unit_id,
            'unit' => $item->unit?->name,
            'unit_code' => $item->unit?->code,
            'is_active' => $item->is_active,
        ];
    }
}
