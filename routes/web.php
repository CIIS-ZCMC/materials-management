<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\IarNumberController;
use App\Http\Controllers\Admin\PropertyNumberController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\DeliveryDocumentController;
use App\Http\Controllers\DeliveryItemController;
use App\Http\Controllers\ItemCategoryController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\PersonnelController;
use App\Http\Controllers\PoDocumentController;
use App\Http\Controllers\PoPrintController;
use App\Http\Controllers\PropertyDocumentController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\UnitController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    // Steps 3.2, 3.3, 3.5 and 3.6: PO list, detail, create, edit, record extension, verify, escalate
    // and send back. The section gate opens the pages; PurchaseOrderPolicy checks po.view / po.create /
    // po.edit (and the status rule for edits); extensions need extension.manage (RecordExtensionRequest)
    // and ExtensionRule; verify needs po.review, escalate po.escalate, send back po.edit (their Form
    // Requests) and PoReviewRule.
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
            Route::post('{purchaseOrder}/extensions', 'storeExtension')->whereNumber('purchaseOrder')->name('extensions.store');
            Route::post('{purchaseOrder}/verify', 'verify')->whereNumber('purchaseOrder')->name('verify');
            Route::post('{purchaseOrder}/escalate', 'escalate')->whereNumber('purchaseOrder')->name('escalate');
            Route::post('{purchaseOrder}/send-back', 'sendBack')->whereNumber('purchaseOrder')->name('send-back');
        });

    // Steps 4.2, 4.2b and 4.3: the Documents tab. The section gate opens the pages; PurchaseOrderPolicy
    // (po.upload-files, documents.void, documents.view-originals, po.view) and PoDocumentRule decide each
    // action. 'consolidated' is the combined PDF (viewer and ?download=1). Kept outside the group above
    // because it uses a different controller.
    Route::middleware('can:section.purchase-orders')
        ->controller(PoDocumentController::class)
        ->prefix('purchase-orders/{purchaseOrder}/documents')
        ->name('purchase-orders.documents.')
        ->group(function () {
            Route::post('/', 'store')->whereNumber('purchaseOrder')->name('store');
            Route::post('submit', 'submit')->whereNumber('purchaseOrder')->name('submit');
            Route::post('rebuild', 'rebuild')->whereNumber('purchaseOrder')->name('rebuild');
            Route::get('consolidated', 'consolidated')->whereNumber('purchaseOrder')->name('consolidated');
            Route::get('{document}/file', 'file')->whereNumber(['purchaseOrder', 'document'])->name('file');
            Route::post('{document}/move', 'move')->whereNumber(['purchaseOrder', 'document'])->name('move');
            Route::post('{document}/void', 'void')->whereNumber(['purchaseOrder', 'document'])->name('void');
            Route::post('{document}/replace', 'replace')->whereNumber(['purchaseOrder', 'document'])->name('replace');
            Route::delete('{document}', 'destroy')->whereNumber(['purchaseOrder', 'document'])->name('destroy');
        });

    // Step 4.6: "Print PO" (form ZCMC-F-PROC-13). po.view and PoPrintRule are checked by PurchaseOrderPolicy::printOrder
    // (a BizBox PO is refused). The PDF is generated on each call, never stored, and every call is audited (po_printed).
    // ?download=1 sends it as a file instead of opening it in the viewer.
    Route::get('purchase-orders/{purchaseOrder}/print', PoPrintController::class)
        ->middleware('can:section.purchase-orders')
        ->whereNumber('purchaseOrder')
        ->name('purchase-orders.print');

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

    // Step 2.3 / 5.0: read-only personnel directory (dummy UMIS) for the pickers on the delivery and
    // property screens. No menu section: it is JSON, not a page. PersonnelPolicy::viewAny (checked in
    // SearchPersonnelRequest) allows delivery.encode, delivery.upload-docs, par.generate or ics.generate.
    // Query: ?search=&role=inspector|end-user|property-custodian&limit=
    Route::get('personnel/search', [PersonnelController::class, 'search'])->name('personnel.search');

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

    // Step 5.2: delivery batches (one IAR each). The section gate opens the pages; DeliveryPolicy checks
    // delivery.encode (record, edit, void) and DeliveryRule checks the PO and delivery status. Reading needs
    // po.view or a delivery.* permission. 'create' and 'iar-suggestion' must stay above the {delivery} routes.
    // Create needs ?purchase_order=ID (a delivery always belongs to a PO).
    Route::middleware('can:section.deliveries')
        ->controller(DeliveryController::class)
        ->prefix('deliveries')
        ->name('deliveries.')
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('create', 'create')->name('create');
            Route::get('iar-suggestion', 'iarSuggestion')->name('iar-suggestion');
            Route::post('/', 'store')->name('store');
            Route::get('{delivery}', 'show')->whereNumber('delivery')->name('show');
            Route::get('{delivery}/edit', 'edit')->whereNumber('delivery')->name('edit');
            Route::put('{delivery}', 'update')->whereNumber('delivery')->name('update');
            Route::post('{delivery}/void', 'voidDelivery')->whereNumber('delivery')->name('void');
        });

    // Step 5.3: the documents of a delivery (DR, OR, IAR ...). DeliveryPolicy decides each action (delivery.upload-docs,
    // documents.void for void/replace) and DeliveryDocumentRule the delivery status. A file is always the one of the
    // delivery in the URL (404 otherwise). Same section gate as above.
    Route::middleware('can:section.deliveries')
        ->controller(DeliveryDocumentController::class)
        ->prefix('deliveries/{delivery}')
        ->where(['delivery' => '[0-9]+', 'document' => '[0-9]+'])
        ->name('deliveries.documents.')
        ->group(function () {
            Route::post('documents', 'store')->name('store');
            Route::post('documents/complete', 'complete')->name('complete');
            Route::post('documents/reopen', 'reopen')->name('reopen');
            Route::post('documents/{document}/void', 'void')->name('void');
            Route::post('documents/{document}/replace', 'replace')->name('replace');
            Route::get('documents/{document}/file', 'file')->name('file');
        });

    // Step 6.1: the delivered items of a delivery (the PO Reviewer's delivery update: quantity per PO line and date).
    // DeliveryPolicy decides each action (delivery.update-items) and DeliveryItemRule the delivery status. A row is always
    // the one of the delivery in the URL (404 otherwise). Same section gate as above (it already holds delivery.update-items).
    Route::middleware('can:section.deliveries')
        ->controller(DeliveryItemController::class)
        ->prefix('deliveries/{delivery}/items')
        ->where(['delivery' => '[0-9]+', 'item' => '[0-9]+'])
        ->name('deliveries.items.')
        ->group(function () {
            Route::get('create', 'create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::post('{item}/void', 'void')->name('void');
        });

    // Step 6.2: equipment decision and property records (PAR / Sub-PAR / ICS). The section gate opens the pages;
    // PropertyPolicy decides each action (equipment.decide, par.generate, ics.generate) and PropertyDocumentRule the
    // state of the line. 'number-suggestion' must stay above the other GET routes.
    Route::middleware('can:section.property-documents')
        ->controller(PropertyDocumentController::class)
        ->prefix('property-documents')
        ->where(['item' => '[0-9]+', 'decision' => '[0-9]+', 'document' => '[0-9]+'])
        ->name('property-documents.')
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('number-suggestion', 'numberSuggestion')->name('number-suggestion');
            Route::get('items/{item}', 'show')->name('show');
            Route::post('items/{item}/decision', 'decide')->name('decide');
            Route::post('decisions/{decision}/void', 'voidDecision')->name('decisions.void');
            Route::post('decisions/{decision}/documents', 'issue')->name('documents.issue');
            Route::post('documents/{document}/void', 'voidDocument')->name('documents.void');
        });

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

        // Step 5.1: IAR number series (Admin). The section gate opens the page; IarNumberPolicy
        // and SetIarCounterRequest check iar.manage.
        Route::middleware('can:section.iar-numbers')
            ->controller(IarNumberController::class)
            ->group(function () {
                Route::get('iar-numbers', 'index')->name('iar-numbers.index');
                Route::put('iar-numbers/counter', 'setCounter')->name('iar-numbers.counter');
            });

        // Step 6.2: PAR / Sub-PAR / ICS number counters (Admin). PropertySeriesPolicy and SetPropertyCounterRequest
        // check property.manage-numbers.
        Route::middleware('can:section.property-numbers')
            ->controller(PropertyNumberController::class)
            ->group(function () {
                Route::get('property-numbers', 'index')->name('property-numbers.index');
                Route::put('property-numbers/counter', 'setCounter')->name('property-numbers.counter');
            });
    });
});

require __DIR__.'/settings.php';