<?php

namespace App\Integrations\Personnel;

use App\Contracts\PersonnelDirectory;
use App\Contracts\PersonnelRepositoryInterface;
use App\Data\PersonData;
use App\Models\Personnel;

/**
 * The dummy directory: reads the local `personnel` table.
 * Phase 13 adds a UMIS class beside this one and changes the binding in AppServiceProvider.
 */
class LocalPersonnelDirectory implements PersonnelDirectory
{
    public function __construct(
        private readonly PersonnelRepositoryInterface $personnel,
    ) {}

    public function search(?string $term = null, ?string $role = null, int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));

        return $this->personnel
            ->searchActive(['search' => $term, 'role' => $role], $limit)
            ->map(fn (Personnel $person) => $this->toData($person))
            ->values()
            ->all();
    }

    public function find(int|string $id): ?PersonData
    {
        $person = $this->personnel->findById($id);

        return $person instanceof Personnel ? $this->toData($person) : null;
    }

    private function toData(Personnel $person): PersonData
    {
        return new PersonData(
            id: $person->id,
            employeeNo: $person->employee_no,
            name: $person->name,
            position: $person->position,
            office: $person->office,
        );
    }
}