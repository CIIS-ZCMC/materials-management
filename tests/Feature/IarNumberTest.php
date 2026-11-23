<?php

use App\Enums\IarNumberStatus;
use App\Enums\IarSeriesType;
use App\Models\IarNumber;
use App\Models\IarSeries;
use App\Models\User;
use App\Services\IarNumberService;
use Database\Seeders\IarSeriesSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

/*
 * Step 5.1: IAR numbering. Relies on tests/Pest.php giving Feature tests RefreshDatabase (as the other
 * Feature tests do). Helper names start with iar_ so they cannot clash with other test files.
 */

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function iar_service(): IarNumberService
{
    return app(IarNumberService::class);
}

function iar_user(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/** Runs $fn and returns the validation errors it throws (empty array if it did not throw). */
function iar_errors(callable $fn): array
{
    try {
        $fn();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

// ---- format ------------------------------------------------------------------------------------

it('writes the three series in their own format', function () {
    expect(IarSeriesType::Regular->format(2026, 1398))->toBe('26-1398')
        ->and(IarSeriesType::Contract->format(2026, 15))->toBe('C26-0015')
        ->and(IarSeriesType::Donation->format(2026, 1))->toBe('D26-0001')
        ->and(IarSeriesType::Regular->format(2026, 12345))->toBe('26-12345');
});

// ---- suggesting and reserving -------------------------------------------------------------------

it('starts a new series at 1 and does not use a number up by suggesting it', function () {
    $first = iar_service()->suggest(IarSeriesType::Regular, 2026);
    $again = iar_service()->suggest(IarSeriesType::Regular, 2026);

    expect($first->number)->toBe('26-0001')
        ->and($again->number)->toBe('26-0001')
        ->and(IarNumber::count())->toBe(0);
});

it('gives a different number each time one is suggested and then reserved', function () {
    $a = iar_service()->suggest(IarSeriesType::Regular, 2026);
    iar_service()->reserve(IarSeriesType::Regular, 2026, $a->number);

    $b = iar_service()->suggest(IarSeriesType::Regular, 2026);
    iar_service()->reserve(IarSeriesType::Regular, 2026, $b->number);

    $c = iar_service()->suggest(IarSeriesType::Regular, 2026);

    expect([$a->number, $b->number, $c->number])->toBe(['26-0001', '26-0002', '26-0003'])
        ->and(IarNumber::count())->toBe(2);
});

it('counts the three series and the years separately', function () {
    iar_service()->reserve(IarSeriesType::Regular, 2026, '26-0001');

    expect(iar_service()->suggest(IarSeriesType::Contract, 2026)->number)->toBe('C26-0001')
        ->and(iar_service()->suggest(IarSeriesType::Donation, 2026)->number)->toBe('D26-0001')
        ->and(iar_service()->suggest(IarSeriesType::Regular, 2027)->number)->toBe('27-0001');
});

it('rejects a duplicate number with a clear message', function () {
    iar_service()->reserve(IarSeriesType::Regular, 2026, '26-1398');

    $errors = iar_errors(fn () => iar_service()->reserve(IarSeriesType::Regular, 2026, '26-1398'));

    expect($errors)->toHaveKey('iar_number')
        ->and($errors['iar_number'][0])->toContain('26-1398')->toContain('already in use')
        ->and(IarNumber::count())->toBe(1);
});

it('lets staff type a number above the counter and moves the counter past it', function () {
    iar_service()->reserve(IarSeriesType::Regular, 2026, '26-1398');

    expect(IarSeries::first()->next_number)->toBe(1399)
        ->and(iar_service()->suggest(IarSeriesType::Regular, 2026)->number)->toBe('26-1399');
});

it('accepts a number below the counter that was never used', function () {
    iar_service()->reserve(IarSeriesType::Regular, 2026, '26-0010');
    iar_service()->reserve(IarSeriesType::Regular, 2026, '26-0004');

    expect(IarNumber::count())->toBe(2)
        ->and(IarSeries::first()->next_number)->toBe(11);
});

it('rejects numbers in the wrong spelling, series or year', function (string $number) {
    $errors = iar_errors(fn () => iar_service()->reserve(IarSeriesType::Regular, 2026, $number));

    expect($errors)->toHaveKey('iar_number')->and(IarNumber::count())->toBe(0);
})->with(['26-01398', '26-98', 'C26-0015', '25-1398', '26/1398', 'abc', '26-0000']);

it('keeps a delivery id on the reserved number when one is given', function () {
    $row = iar_service()->reserve(IarSeriesType::Contract, 2026, 'C26-0015', 42);

    expect($row->delivery_id)->toBe(42)->and($row->status)->toBe(IarNumberStatus::Used);
});

// ---- voiding ------------------------------------------------------------------------------------

it('never suggests or accepts a voided number again', function () {
    $a = iar_service()->reserve(IarSeriesType::Regular, 2026, '26-0001');
    iar_service()->void($a, 'Entered by mistake');

    expect(iar_service()->suggest(IarSeriesType::Regular, 2026)->number)->toBe('26-0002');

    $errors = iar_errors(fn () => iar_service()->reserve(IarSeriesType::Regular, 2026, '26-0001'));

    expect($errors['iar_number'][0])->toContain('voided earlier')
        ->and(IarNumber::count())->toBe(1);
});

it('keeps the voided row with its reason and writes an audit entry', function () {
    $user = iar_user('Admin');
    $this->actingAs($user);

    $row = iar_service()->reserve(IarSeriesType::Regular, 2026, '26-0001');
    iar_service()->void($row, 'Wrong supplier');

    $row->refresh();
    expect($row->status)->toBe(IarNumberStatus::Void)
        ->and($row->void_reason)->toBe('Wrong supplier')
        ->and($row->voided_by)->toBe($user->id)
        ->and($row->voided_at)->not->toBeNull();

    $entry = Activity::query()->where('log_name', 'iar')->where('event', 'iar_number_voided')->first();

    expect($entry)->not->toBeNull()
        ->and(data_get($entry->properties, 'reason'))->toBe('Wrong supplier');
});

it('requires a reason to void and refuses to void twice', function () {
    $row = iar_service()->reserve(IarSeriesType::Regular, 2026, '26-0001');

    expect(iar_errors(fn () => iar_service()->void($row, '')))->toHaveKey('reason')
        ->and(iar_errors(fn () => iar_service()->void($row, 'abc')))->toHaveKey('reason')
        ->and($row->fresh()->status)->toBe(IarNumberStatus::Used);

    iar_service()->void($row, 'Wrong supplier');

    expect(iar_errors(fn () => iar_service()->void($row, 'Wrong supplier again')))->toHaveKey('iar_number');
});

// ---- counter ------------------------------------------------------------------------------------

it('writes an audit entry when the counter changes', function () {
    $this->actingAs(iar_user('Admin'));

    $changed = iar_service()->setCounter(IarSeriesType::Regular, 2026, 1399);

    $entry = Activity::query()->where('log_name', 'iar')->where('event', 'iar_counter_set')->latest('id')->first();

    expect($changed)->toBeTrue()
        ->and($entry)->not->toBeNull()
        ->and(data_get($entry->properties, 'old.next_number'))->toBe(1)
        ->and(data_get($entry->properties, 'new.next_number'))->toBe(1399)
        ->and(iar_service()->suggest(IarSeriesType::Regular, 2026)->number)->toBe('26-1399');
});

it('writes no audit entry when the counter keeps its value', function () {
    iar_service()->setCounter(IarSeriesType::Regular, 2026, 50);

    expect(iar_service()->setCounter(IarSeriesType::Regular, 2026, 50))->toBeFalse()
        ->and(Activity::query()->where('event', 'iar_counter_set')->count())->toBe(1);
});

it('refuses a counter at or below the highest number already recorded', function () {
    iar_service()->reserve(IarSeriesType::Regular, 2026, '26-0010');
    $voided = iar_service()->reserve(IarSeriesType::Regular, 2026, '26-0011');
    iar_service()->void($voided, 'Entered by mistake');

    // 11 is void but still recorded, so the counter must stay above it.
    expect(iar_errors(fn () => iar_service()->setCounter(IarSeriesType::Regular, 2026, 11)))->toHaveKey('next_number')
        ->and(iar_errors(fn () => iar_service()->setCounter(IarSeriesType::Regular, 2026, 5)))->toHaveKey('next_number')
        ->and(IarSeries::first()->next_number)->toBe(12);

    expect(iar_service()->setCounter(IarSeriesType::Regular, 2026, 20))->toBeTrue();
});

it('refuses a counter outside 1 to 999999 and a year outside 2000 to 2099', function () {
    expect(iar_errors(fn () => iar_service()->setCounter(IarSeriesType::Regular, 2026, 0)))->toHaveKey('next_number')
        ->and(iar_errors(fn () => iar_service()->setCounter(IarSeriesType::Regular, 2026, 1000000)))->toHaveKey('next_number')
        ->and(iar_errors(fn () => iar_service()->setCounter(IarSeriesType::Regular, 1999, 5)))->toHaveKey('year');
});

// ---- screen and access --------------------------------------------------------------------------

it('lets only the Admin open the IAR numbers screen', function () {
    $this->actingAs(iar_user('Admin'))
        ->get('/admin/iar-numbers')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/iar-numbers/index')
            ->has('series', 3)
            ->has('voided.data', 0));

    foreach (['Procurement', 'MMS Head', 'MMS Staff', 'Finance'] as $role) {
        $this->actingAs(iar_user($role))->get('/admin/iar-numbers')->assertForbidden();
    }
});

it('lets the Admin set a counter from the screen and blocks everyone else', function () {
    $this->actingAs(iar_user('Admin'))
        ->put('/admin/iar-numbers/counter', ['type' => 'contract', 'year' => 2026, 'next_number' => 16])
        ->assertRedirect(route('admin.iar-numbers.index'));

    expect(iar_service()->suggest(IarSeriesType::Contract, 2026)->number)->toBe('C26-0016');

    $this->actingAs(iar_user('Procurement'))
        ->put('/admin/iar-numbers/counter', ['type' => 'contract', 'year' => 2026, 'next_number' => 99])
        ->assertForbidden();

    expect(IarSeries::first()->next_number)->toBe(16);
});

it('shows a validation error when the counter is too low', function () {
    iar_service()->reserve(IarSeriesType::Regular, 2026, '26-0010');

    $this->actingAs(iar_user('Admin'))
        ->put('/admin/iar-numbers/counter', ['type' => 'regular', 'year' => 2026, 'next_number' => 5])
        ->assertSessionHasErrors('next_number');
});

it('declares the permission and the section only for the Admin role', function () {
    expect(config('mms_access.permissions'))->toContain('iar.manage')
        ->and(config('mms_access.roles.Admin'))->toContain('iar.manage')
        ->and(config('mms_access.sections.iar-numbers'))->toBe(['iar.manage']);

    foreach (config('mms_access.roles') as $role => $permissions) {
        if ($role !== 'Admin') {
            expect($permissions)->not->toContain('iar.manage');
        }
    }

    foreach (config('mms_access.job_presets') as $preset) {
        expect($preset['permissions'])->not->toContain('iar.manage');
    }
});

// ---- seeder -------------------------------------------------------------------------------------

it('seeds the three 2026 counters once in development', function () {
    (new IarSeriesSeeder)->run();
    (new IarSeriesSeeder)->run();

    expect(IarSeries::count())->toBe(3)
        ->and(iar_service()->suggest(IarSeriesType::Regular, 2026)->number)->toBe('26-7016')
        ->and(iar_service()->suggest(IarSeriesType::Contract, 2026)->number)->toBe('C26-7013')
        ->and(iar_service()->suggest(IarSeriesType::Donation, 2026)->number)->toBe('D26-7011');
});

it('refuses to seed counters in production', function () {
    app()->detectEnvironment(fn () => 'production');

    (new IarSeriesSeeder)->run();

    expect(IarSeries::count())->toBe(0);
});