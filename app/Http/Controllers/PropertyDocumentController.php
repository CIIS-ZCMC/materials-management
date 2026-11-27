<?php

namespace App\Http\Controllers;

use App\Contracts\PropertyRepositoryInterface;
use App\Enums\PropertyDocumentType;
use App\Http\Requests\DecidePropertyRequest;
use App\Http\Requests\IssuePropertyDocumentRequest;
use App\Http\Requests\VoidPropertyRecordRequest;
use App\Models\DeliveryItem;
use App\Models\PropertyDecision;
use App\Models\PropertyDocument;
use App\Services\PropertyDecisionService;
use App\Services\PropertyDocumentService;
use App\Services\PropertyNumberService;
use App\Services\PropertyOverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 6.2: equipment decision and property records (PAR / Sub-PAR / ICS). Thin: checks permission (Policy), then
 * calls the Services, which hold the rules (PropertyDocumentRule). The routes sit behind the section.property-documents gate.
 */
class PropertyDocumentController
{
    public function index(Request $request, PropertyOverviewService $overview): Response
    {
        Gate::authorize('viewAny', PropertyDecision::class);

        return Inertia::render('property-documents/index', $overview->listing(
            (string) $request->query('tab', PropertyRepositoryInterface::TAB_PENDING),
            trim((string) $request->query('search', '')),
        ));
    }

    public function show(DeliveryItem $item, Request $request, PropertyOverviewService $overview): Response
    {
        Gate::authorize('view', PropertyDecision::class);

        return Inertia::render('property-documents/show', $overview->sheet($item, $request->user()));
    }

    public function decide(DeliveryItem $item, DecidePropertyRequest $request, PropertyDecisionService $service): RedirectResponse
    {
        Gate::authorize('decide', [PropertyDecision::class, $item]);

        $decision = $service->decide(
            $item,
            $request->boolean('is_equipment'),
            $request->validated('override_reason'),
            $request->validated('remarks'),
            $request->user(),
        );

        return $this->back($item->id, 'Decision recorded: '.$decision->band->label().'.');
    }

    public function voidDecision(PropertyDecision $decision, VoidPropertyRecordRequest $request, PropertyDecisionService $service): RedirectResponse
    {
        Gate::authorize('voidDecision', $decision);

        $service->voidDecision($decision, (string) $request->validated('reason'), $request->user());

        return $this->back($decision->delivery_item_id, 'Decision voided. You can decide again.');
    }

    public function issue(PropertyDecision $decision, IssuePropertyDocumentRequest $request, PropertyDocumentService $service): RedirectResponse
    {
        $type = PropertyDocumentType::from($request->validated('type'));

        Gate::authorize('issue', [$decision, $type]);

        $made = $service->issue(
            $decision,
            $type,
            (string) $request->validated('number'),
            $request->validated('sub_par_number'),
            $request->validated('person_id'),
            $request->user(),
        );

        $numbers = implode(' and ', array_map(fn (PropertyDocument $d) => $d->number, $made));

        return $this->back($decision->delivery_item_id, "Issued {$numbers}.");
    }

    public function voidDocument(PropertyDocument $document, VoidPropertyRecordRequest $request, PropertyDocumentService $service): RedirectResponse
    {
        Gate::authorize('voidDocument', $document);

        $itemId = $document->decision->delivery_item_id;
        $voided = $service->void($document, (string) $request->validated('reason'), $request->user());

        $numbers = implode(' and ', array_map(fn (PropertyDocument $d) => $d->number, $voided));

        return $this->back($itemId, "Voided {$numbers}. The number is never reused.");
    }

    /** GET /property-documents/number-suggestion?type=par . The next free number; it is not used up until a document is issued. */
    public function numberSuggestion(Request $request, PropertyNumberService $numbers): JsonResponse
    {
        $data = $request->validate(['type' => ['required', Rule::enum(PropertyDocumentType::class)]]);
        $type = PropertyDocumentType::from($data['type']);

        Gate::authorize('suggestNumber', [PropertyDecision::class, $type]);

        return response()->json($numbers->suggest($type));
    }

    private function back(int $itemId, string $message): RedirectResponse
    {
        return redirect()->route('property-documents.show', $itemId)->with('success', $message);
    }
}
