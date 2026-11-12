<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveItemCategoryRequest;
use App\Models\ItemCategory;
use App\Services\ItemCategoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ItemCategoryController extends Controller
{
    public function __construct(private readonly ItemCategoryService $service) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ItemCategory::class);

        $search = trim((string) $request->query('search', ''));
        $paginator = $this->service->paginate(['search' => $search]);

        return Inertia::render('catalog/categories/index', [
            'categories' => [
                'data' => $paginator->getCollection()
                    ->map(fn (ItemCategory $category) => $this->row($category))
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
            'canManage' => $request->user()->can('create', ItemCategory::class),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', ItemCategory::class);

        return Inertia::render('catalog/categories/create');
    }

    public function store(SaveItemCategoryRequest $request): RedirectResponse
    {
        $this->service->create($request->validated());

        return to_route('catalog.categories.index')->with('success', 'Category created.');
    }

    public function edit(ItemCategory $category): Response
    {
        Gate::authorize('update', $category);

        return Inertia::render('catalog/categories/edit', [
            'category' => $this->row($category),
        ]);
    }

    public function update(SaveItemCategoryRequest $request, ItemCategory $category): RedirectResponse
    {
        $this->service->update($category, $request->validated());

        return to_route('catalog.categories.index')->with('success', 'Category updated.');
    }

    public function toggleActive(Request $request, ItemCategory $category): RedirectResponse
    {
        Gate::authorize('toggleActive', $category);

        $active = $request->boolean('active');
        $this->service->setActive($category, $active);

        return back()->with('success', $active ? 'Category activated.' : 'Category deactivated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ItemCategory $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'is_equipment' => $category->is_equipment,
            'is_active' => $category->is_active,
        ];
    }
}
