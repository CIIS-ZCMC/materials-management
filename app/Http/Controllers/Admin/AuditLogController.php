<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class AuditLogController extends Controller
{
    public function __construct(private readonly AuditLogService $service) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Activity::class);

        $filters = $this->service->normalizeFilters(
            $request->only(['causer', 'from', 'to', 'subject_type', 'search', 'sort']),
        );

        $paginator = $this->service->paginate($filters);

        return Inertia::render('admin/audit-log/index', [
            'logs' => [
                'data' => $paginator->getCollection()->values(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'prev_page_url' => $paginator->previousPageUrl(),
                'next_page_url' => $paginator->nextPageUrl(),
            ],
            'filters' => $filters,
            // Closures: skipped on partial reloads (only logs + filters are asked for).
            'causers' => fn () => $this->service->causerOptions(),
            'subjectTypes' => fn () => $this->service->subjectTypeOptions(),
            'timezone' => config('mms.display_timezone'),
        ]);
    }
}