<?php

namespace App\Data;

/**
 * One person as the rest of the app sees them, whatever the source (dummy table or UMIS).
 */
final readonly class PersonData
{
    public function __construct(
        public int|string $id,
        public string $employeeNo,
        public string $name,
        public ?string $position,
        public ?string $office,
    ) {}

    /**
     * @return array{id: int|string, employee_no: string, name: string, position: string|null, office: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'employee_no' => $this->employeeNo,
            'name' => $this->name,
            'position' => $this->position,
            'office' => $this->office,
        ];
    }
}