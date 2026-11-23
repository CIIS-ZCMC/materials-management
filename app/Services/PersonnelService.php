<?php

namespace App\Services;

use App\Contracts\PersonnelDirectory;
use App\Data\PersonData;

class PersonnelService
{
    public function __construct(
        private readonly PersonnelDirectory $directory,
    ) {}

    /**
     * @return list<PersonData>
     */
    public function search(?string $term, ?string $role, ?int $limit): array
    {
        $term = trim((string) $term);

        return $this->directory->search(
            $term === '' ? null : $term,
            $role,
            $limit ?? PersonnelDirectory::DEFAULT_LIMIT,
        );
    }
}