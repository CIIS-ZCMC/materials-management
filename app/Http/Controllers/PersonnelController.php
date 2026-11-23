<?php

namespace App\Http\Controllers;

use App\Data\PersonData;
use App\Http\Requests\SearchPersonnelRequest;
use App\Services\PersonnelService;
use Illuminate\Http\JsonResponse;

class PersonnelController extends Controller
{
    public function __construct(private readonly PersonnelService $service) {}

    /**
     * GET /personnel/search?search=&role=&limit=
     * Read-only JSON for the pickers on the delivery and property screens.
     */
    public function search(SearchPersonnelRequest $request): JsonResponse
    {
        $data = $request->validated();

        $people = $this->service->search(
            $data['search'] ?? null,
            $data['role'] ?? null,
            isset($data['limit']) ? (int) $data['limit'] : null,
        );

        return response()->json([
            'data' => array_map(fn (PersonData $person) => $person->toArray(), $people),
        ]);
    }
}