<?php

use App\Contracts\PersonnelDirectory;
use App\Contracts\PersonnelRepositoryInterface;
use App\Data\PersonData;
use App\Integrations\Personnel\LocalPersonnelDirectory;
use App\Models\Personnel;
use App\Models\User;
use App\Policies\PersonnelPolicy;
use Database\Seeders\DummyUserSeeder;
use Database\Seeders\PersonnelSeeder;
use Database\Seeders\RolePermissionSeeder;

/*
| Step 2.3 / 5.0: personnel directory (dummy UMIS). Users come from the dummy seeders:
| DUMMY-0004 Encoder, DUMMY-0005 Inventory Team, and so on (CONTEXT.md).
*/

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(DummyUserSeeder::class);
});

function personnelSignIn(string $employeeNo): User
{
    $user = User::query()->where('employee_no', $employeeNo)->firstOrFail();
    $user->forceFill(['email_verified_at' => now()])->save();

    test()->actingAs($user);

    return $user;
}

// ---------------------------------------------------------------- access

it('lets the Encoder and the Inventory Team search the directory', function (string $employeeNo) {
    personnelSignIn($employeeNo);
    Personnel::factory()->create(['name' => 'Alpha Sample']);

    $this->getJson('/personnel/search')->assertOk()->assertJsonCount(1, 'data');
})->with([
    'Encoder' => 'DUMMY-0004',
    'Inventory Team' => 'DUMMY-0005',
]);

it('refuses everyone else with 403', function (string $employeeNo) {
    personnelSignIn($employeeNo);

    $this->getJson('/personnel/search')->assertForbidden();
})->with([
    'Admin' => 'DUMMY-0001',
    'Procurement' => 'DUMMY-0002',
    'PO Reviewer' => 'DUMMY-0003',
    'MMS Head' => 'DUMMY-0006',
    'Finance' => 'DUMMY-0007',
    'DV Processor' => 'DUMMY-0008',
    'Messenger' => 'DUMMY-0009',
]);

it('sends a guest to the login page', function () {
    $this->get('/personnel/search')->assertRedirect('/login');
});

it('only names permissions that exist in config/mms_access.php', function () {
    expect(array_diff(PersonnelPolicy::PERMISSIONS, config('mms_access.permissions')))->toBe([]);
});

// ---------------------------------------------------------------- search

it('finds people by name and returns only the fields the screen needs', function () {
    personnelSignIn('DUMMY-0004');
    Personnel::factory()->create(['name' => 'Alpha Sample']);
    Personnel::factory()->create(['name' => 'Beta Dummy']);

    $response = $this->getJson('/personnel/search?search=alph')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.name'))->toBe('Alpha Sample')
        ->and(array_keys($response->json('data.0')))
        ->toEqualCanonicalizing(['id', 'employee_no', 'name', 'position', 'office']);
});

it('finds people by employee number too', function () {
    personnelSignIn('DUMMY-0004');
    Personnel::factory()->create(['name' => 'Alpha Sample', 'employee_no' => 'UMIS-FAKE-777777']);
    Personnel::factory()->create(['name' => 'Beta Dummy']);

    $this->getJson('/personnel/search?search=777777')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Alpha Sample');
});

it('lists people ordered by name when there is no search text', function () {
    personnelSignIn('DUMMY-0004');
    Personnel::factory()->create(['name' => 'Zulu Sample']);
    Personnel::factory()->create(['name' => 'Alpha Sample']);

    expect($this->getJson('/personnel/search')->json('data.*.name'))->toBe(['Alpha Sample', 'Zulu Sample']);
});

it('never lists inactive people', function () {
    personnelSignIn('DUMMY-0004');
    Personnel::factory()->inactive()->create(['name' => 'Gone Person']);

    $this->getJson('/personnel/search?search=gone')->assertOk()->assertJsonCount(0, 'data');
});

it('treats % and _ in the search text as plain characters', function () {
    personnelSignIn('DUMMY-0004');
    Personnel::factory()->create(['name' => 'Alpha Sample']);

    $this->getJson('/personnel/search?search=%25')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/personnel/search?search=_')->assertOk()->assertJsonCount(0, 'data');
});

// ---------------------------------------------------------------- role filter

it('filters by role', function () {
    personnelSignIn('DUMMY-0004');
    Personnel::factory()->inspector()->create(['name' => 'Ins One']);
    Personnel::factory()->endUser()->create(['name' => 'End One']);
    Personnel::factory()->propertyCustodian()->create(['name' => 'Cus One']);
    Personnel::factory()->inspector()->endUser()->create(['name' => 'Both Roles']);
    Personnel::factory()->create(['name' => 'No Role']);

    expect($this->getJson('/personnel/search?role=inspector')->json('data.*.name'))->toBe(['Both Roles', 'Ins One'])
        ->and($this->getJson('/personnel/search?role=end-user')->json('data.*.name'))->toBe(['Both Roles', 'End One'])
        ->and($this->getJson('/personnel/search?role=property-custodian')->json('data.*.name'))->toBe(['Cus One']);
});

it('combines the role filter with the name search', function () {
    personnelSignIn('DUMMY-0004');
    Personnel::factory()->inspector()->create(['name' => 'Ana Inspector']);
    Personnel::factory()->inspector()->create(['name' => 'Ben Inspector']);
    Personnel::factory()->endUser()->create(['name' => 'Ana EndUser']);

    expect($this->getJson('/personnel/search?role=inspector&search=ana')->json('data.*.name'))->toBe(['Ana Inspector']);
});

it('refuses an unknown role with a validation error', function () {
    personnelSignIn('DUMMY-0004');

    $this->getJson('/personnel/search?role=wizard')->assertStatus(422)->assertJsonValidationErrors(['role']);
});

it('returns nobody for an unknown role at the repository level (never widens the list)', function () {
    Personnel::factory()->inspector()->create();

    expect(app(PersonnelRepositoryInterface::class)->searchActive(['role' => 'wizard']))->toHaveCount(0);
});

// ---------------------------------------------------------------- limit

it('returns 20 people by default and respects the limit', function () {
    personnelSignIn('DUMMY-0004');
    Personnel::factory()->count(25)->create();

    expect($this->getJson('/personnel/search')->json('data'))->toHaveCount(20)
        ->and($this->getJson('/personnel/search?limit=2')->json('data'))->toHaveCount(2);
});

it('refuses a limit above 50 or below 1', function () {
    personnelSignIn('DUMMY-0004');

    $this->getJson('/personnel/search?limit=51')->assertStatus(422)->assertJsonValidationErrors(['limit']);
    $this->getJson('/personnel/search?limit=0')->assertStatus(422)->assertJsonValidationErrors(['limit']);
});

// ---------------------------------------------------------------- the interface

it('binds the local implementation to the PersonnelDirectory interface', function () {
    expect(app(PersonnelDirectory::class))->toBeInstanceOf(LocalPersonnelDirectory::class);
});

it('returns plain PersonData objects and keeps find() working for inactive people', function () {
    $active = Personnel::factory()->inspector()->create(['name' => 'Alpha Sample']);
    $gone = Personnel::factory()->inactive()->create(['name' => 'Gone Person']);
    $directory = app(PersonnelDirectory::class);

    $found = $directory->search('alpha', PersonnelDirectory::ROLE_INSPECTOR);

    expect($found)->toHaveCount(1)
        ->and($found[0])->toBeInstanceOf(PersonData::class)
        ->and($found[0]->id)->toBe($active->id)
        ->and($directory->search('gone'))->toBe([])
        ->and($directory->find($gone->id)?->name)->toBe('Gone Person')
        ->and($directory->find(999999))->toBeNull();
});

it('keeps the role names in the model in step with the interface', function () {
    expect(array_keys(Personnel::ROLE_COLUMNS))->toEqualCanonicalizing(PersonnelDirectory::ROLES);
});

// ---------------------------------------------------------------- the seeder

it('seeds about 30 clearly fake people with every role, one of them inactive', function () {
    $this->seed(PersonnelSeeder::class);

    expect(Personnel::count())->toBe(30)
        ->and(Personnel::where('is_active', false)->count())->toBe(1)
        ->and(Personnel::where('is_inspector', true)->where('is_active', true)->exists())->toBeTrue()
        ->and(Personnel::where('is_end_user', true)->where('is_active', true)->exists())->toBeTrue()
        ->and(Personnel::where('is_property_custodian', true)->where('is_active', true)->exists())->toBeTrue()
        ->and(Personnel::where('employee_no', 'not like', 'UMIS-DUMMY-%')->count())->toBe(0);
});

it('can be seeded twice without duplicates', function () {
    $this->seed(PersonnelSeeder::class);
    $this->seed(PersonnelSeeder::class);

    expect(Personnel::count())->toBe(30);
});

it('refuses to seed in production', function () {
    $this->app['env'] = 'production';

    try {
        (new PersonnelSeeder)->run(); // not $this->seed(): that asks "run in production?" and waits for a key
    } finally {
        $this->app['env'] = 'testing';
    }

    expect(Personnel::count())->toBe(0);
});