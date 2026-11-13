<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\ItemCategoryController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\UnitController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    // Steps 3.2 and 3.3: PO list, detail, create and edit. The section gate opens the pages;
    // PurchaseOrderPolicy checks po.view / po.create / po.edit (and the status rule for edits).
    // 'item-search' and 'create' must stay above the {purchaseOrder} routes.
    Route::middleware('can:section.purchase-orders')
        ->controller(PurchaseOrderController::class)
        ->prefix('purchase-orders')
        ->name('purchase-orders.')
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('create', 'create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::get('item-search', 'itemSearch')->name('item-search');
            Route::get('{purchaseOrder}', 'show')->whereNumber('purchaseOrder')->name('show');
            Route::get('{purchaseOrder}/edit', 'edit')->whereNumber('purchaseOrder')->name('edit');
            Route::put('{purchaseOrder}', 'update')->whereNumber('purchaseOrder')->name('update');
        });

    // Step 2.1: the section gate opens the menu; SupplierPolicy decides who
    // may change data (suppliers.manage) and who may only view (suppliers.view).
    Route::middleware('can:section.suppliers')
        ->controller(SupplierController::class)
        ->prefix('suppliers')
        ->name('suppliers.')
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('create', 'create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::get('{supplier}/edit', 'edit')->name('edit');
            Route::put('{supplier}', 'update')->name('update');
            Route::patch('{supplier}/active', 'toggleActive')->name('active');
        });

    // Step 2.2: item catalog. One section gate ("catalog") opens the three pages;
    // the policies decide who may change data (catalog.manage) and who may only view
    // (catalog.view). Names: catalog.items.*, catalog.categories.*, catalog.units.*
    Route::prefix('catalog')
        ->name('catalog.')
        ->middleware('can:section.catalog')
        ->group(function () {
            Route::controller(ItemController::class)
                ->prefix('items')
                ->name('items.')
                ->group(function () {
                    Route::get('/', 'index')->name('index');
                    Route::get('create', 'create')->name('create');
                    Route::post('/', 'store')->name('store');
                    Route::get('{item}/edit', 'edit')->name('edit');
                    Route::put('{item}', 'update')->name('update');
                    Route::patch('{item}/active', 'toggleActive')->name('active');
                });

            Route::controller(ItemCategoryController::class)
                ->prefix('categories')
                ->name('categories.')
                ->group(function () {
                    Route::get('/', 'index')->name('index');
                    Route::get('create', 'create')->name('create');
                    Route::post('/', 'store')->name('store');
                    Route::get('{category}/edit', 'edit')->name('edit');
                    Route::put('{category}', 'update')->name('update');
                    Route::patch('{category}/active', 'toggleActive')->name('active');
                });

            Route::controller(UnitController::class)
                ->prefix('units')
                ->name('units.')
                ->group(function () {
                    Route::get('/', 'index')->name('index');
                    Route::get('create', 'create')->name('create');
                    Route::post('/', 'store')->name('store');
                    Route::get('{unit}/edit', 'edit')->name('edit');
                    Route::put('{unit}', 'update')->name('update');
                    Route::patch('{unit}/active', 'toggleActive')->name('active');
                });
        });

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

        // Section gate for the menu; ActivityPolicy enforces audit.view.
        Route::get('audit-log', [AuditLogController::class, 'index'])
            ->middleware('can:section.audit-log')
            ->name('audit-log.index');
    });
});

require __DIR__.'/settings.php';