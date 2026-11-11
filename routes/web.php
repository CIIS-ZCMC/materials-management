<?php

use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    // Placeholder pages for the MMS menu. Each is protected on the server by
    // its section gate (config/mms_access.php, "sections").
    // Later steps replace Route::inertia with controllers.
    Route::inertia('purchase-orders', 'purchase-orders/index')
        ->middleware('can:section.purchase-orders')
        ->name('purchase-orders.index');

    Route::inertia('suppliers', 'suppliers/index')
        ->middleware('can:section.suppliers')
        ->name('suppliers.index');

    Route::inertia('deliveries', 'deliveries/index')
        ->middleware('can:section.deliveries')
        ->name('deliveries.index');

    Route::inertia('property-documents', 'property-documents/index')
        ->middleware('can:section.property-documents')
        ->name('property-documents.index');

    Route::inertia('dv-finance', 'dv-finance/index')
        ->middleware('can:section.dv-finance')
        ->name('dv-finance.index');

    Route::inertia('reports', 'reports/index')
        ->middleware('can:section.reports')
        ->name('reports.index');

    Route::prefix('exceptions')->name('exceptions.')->group(function () {
        Route::inertia('cancellations', 'exceptions/cancellations/index')
            ->middleware('can:section.cancellations')
            ->name('cancellations.index');

        Route::inertia('replacements', 'exceptions/replacements/index')
            ->middleware('can:section.replacements')
            ->name('replacements.index');

        Route::inertia('delays', 'exceptions/delays/index')
            ->middleware('can:section.delays')
            ->name('delays.index');
    });

    Route::prefix('admin')->name('admin.')->group(function () {
        // Section gate for the menu; UserPolicy enforces users.manage / roles.manage.
        Route::middleware('can:section.users')
            ->controller(UserController::class)
            ->group(function () {
                Route::get('users', 'index')->name('users.index');
                Route::get('users/create', 'create')->name('users.create');
                Route::post('users', 'store')->name('users.store');
                Route::get('users/{user}/edit', 'edit')->name('users.edit');
                Route::put('users/{user}', 'update')->name('users.update');
                Route::patch('users/{user}/active', 'toggleActive')->name('users.active');
                Route::put('users/{user}/password', 'resetPassword')->name('users.password');
            });

        Route::inertia('audit-log', 'admin/audit-log/index')
            ->middleware('can:section.audit-log')
            ->name('audit-log.index');
    });
});

require __DIR__.'/settings.php';