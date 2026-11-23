<?php

namespace Database\Factories;

use App\Models\Personnel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Personnel>
 */
class PersonnelFactory extends Factory
{
    protected $model = Personnel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_no' => 'UMIS-FAKE-'.fake()->unique()->numerify('######'),
            'name' => fake()->unique()->name(),
            'position' => fake()->jobTitle(),
            'office' => fake()->randomElement(['Pharmacy', 'Laboratory', 'Nursing Service', 'Engineering and Facilities']),
            'is_inspector' => false,
            'is_end_user' => false,
            'is_property_custodian' => false,
            'is_active' => true,
        ];
    }

    public function inspector(): static
    {
        return $this->state(fn () => ['is_inspector' => true]);
    }

    public function endUser(): static
    {
        return $this->state(fn () => ['is_end_user' => true]);
    }

    public function propertyCustodian(): static
    {
        return $this->state(fn () => ['is_property_custodian' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}