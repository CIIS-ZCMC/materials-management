# 🏗️ MaterialManagementSystem Architecture & Structure Guide

This document outlines the standardized architectural structure implemented in **MaterialManagementSystem**, mirroring the design pattern and organization established in **ZCMC DTRService** (`D:\ZCMC_SYSTEMS\DTRService`).

---

## 📌 Architecture Overview

The system uses a **Layered Architecture (Controller → Service → Repository → Model)** with Dependency Injection and Interface Segregation:

```
┌─────────────────────────────────────────────────────────────┐
│                 HTTP / Routing / Inertia                    │
│                 (routes/web.php, routes/api.php)            │
└──────────────────────────────┬──────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────┐
│                     Controllers Layer                       │
│                 (app/Http/Controllers/*)                    │
│   • Thin controllers                                        │
│   • Form Request validation (app/Http/Requests/*)           │
│   • Resource transformation (app/Http/Resources/*)          │
└──────────────────────────────┬──────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────┐
│                      Services Layer                         │
│                     (app/Services/*)                        │
│   • Core business logic & domain rules                      │
│   • Database transactions ($this->transaction(...))         │
│   • External API & multi-entity orchestration               │
└──────────────────────────────┬──────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────┐
│               Contracts & Repositories Layer                │
│       (app/Contracts/*  ◄──►  app/Repositories/*)           │
│   • Decoupled interface contracts                           │
│   • Eloquent queries & data filtering                       │
│   • Base repository with reusable CRUD operations           │
└──────────────────────────────┬──────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────┐
│                        Models Layer                         │
│                       (app/Models/*)                        │
│   • Eloquent relations, casts, fillable attributes          │
└─────────────────────────────────────────────────────────────┘
```

---

## 📂 Directory Layout & Responsibilities

| Directory | Namespace | Primary Role |
| :--- | :--- | :--- |
| `app/Contracts/` | `App\Contracts` | Interfaces defining data access and service contracts. Promotes dependency inversion and simplifies mocking in tests. |
| `app/Repositories/` | `App\Repositories` | Implementations of repository contracts. Encapsulates database queries, Eloquent builder logic, and caching. |
| `app/Services/` | `App\Services` | Encapsulates domain logic, business calculations, workflows, and database transactions (`DB::transaction`). |
| `app/Traits/` | `App\Traits` | Reusable behavioral traits (e.g. `ApiResponseTrait`, `Filterable`). |
| `app/Http/Controllers/` | `App\Http\Controllers` | Lean controllers that accept requests, delegate to services, and return Inertia responses or API resources. |
| `app/Http/Requests/` | `App\Http\Requests` | Form requests encapsulating authorization rules and input validation. |
| `app/Http/Resources/` | `App\Http\Resources` | Transformation layer formatting model data into consistent JSON responses or frontend payloads. |
| `app/Models/` | `App\Models` | Eloquent entities containing schema definitions, relationships, accessors, and scopes. |
| `app/Providers/` | `App\Providers` | Service providers (e.g. `AppServiceProvider`) where interface-to-implementation bindings are registered. |

---

## 🎯 The 3-Tier Pattern Guidelines

Follow the right pattern based on feature complexity—do not over-engineer simple tasks.

### 🟢 Level 1: Simple CRUD (Controller → Model)
* **When to use:** Pure administrative lookups, simple listing, static reference tables without custom business rules.
* **Flow:** `Controller` queries `Model` directly.
* **Files:**
  - `app/Http/Controllers/CategoryController.php`
  - `app/Models/Category.php`

```php
public function index()
{
    return Category::paginate(15);
}
```

---

### 🟡 Level 2: Business Logic (Controller → Service → Model)
* **When to use:** Features with calculations, notifications, file processing, or multi-step logic, but without requiring data-source abstraction.
* **Flow:** `Controller` calls `Service`, `Service` queries `Model` directly.
* **Files:**
  - `app/Http/Controllers/StockAdjustmentController.php`
  - `app/Services/StockAdjustmentService.php`
  - `app/Models/Item.php`

```php
// Service
class StockAdjustmentService extends BaseService
{
    public function adjust(int $id, int $amount): Item
    {
        return $this->transaction(function () use ($id, $amount) {
            $item = Item::findOrFail($id);
            $item->increment('stock', $amount);
            return $item;
        });
    }
}
```

---

### 🔴 Level 3: Full Enterprise Pattern (Controller → Service → Repository → Model)
* **When to use:** Core enterprise domain entities (e.g., Materials, Requisitions, Purchase Orders, Users), multi-source queries, or complex modules requiring unit testing with mocks (matches `DTRService`).
* **Flow:** `Controller` → `Service` → `Contract` (Interface) → `Repository` → `Model`.
* **Files:**
  - `app/Contracts/MaterialRepositoryInterface.php`
  - `app/Repositories/MaterialRepository.php`
  - `app/Services/MaterialService.php`
  - `app/Http/Controllers/MaterialController.php`
  - `app/Http/Requests/StoreMaterialRequest.php`
  - `app/Http/Resources/MaterialResource.php`
  - `app/Providers/AppServiceProvider.php` (Binding)

---

## 🛠️ Step-by-Step: Adding a New Feature (Level 3 Example)

Suppose you are adding a new domain entity called **`Material`**:

### 1. Create Model and Migration
```bash
php artisan make:model Material -m
```

### 2. Define the Contract Interface
Create `app/Contracts/MaterialRepositoryInterface.php`:
```php
<?php

namespace App\Contracts;

use App\Models\Material;

/**
 * @extends BaseRepositoryInterface<Material>
 */
interface MaterialRepositoryInterface extends BaseRepositoryInterface
{
    public function findByItemCode(string $code): ?Material;
}
```

### 3. Implement the Repository
Create `app/Repositories/MaterialRepository.php`:
```php
<?php

namespace App\Repositories;

use App\Contracts\MaterialRepositoryInterface;
use App\Models\Material;

/**
 * @extends BaseRepository<Material>
 */
class MaterialRepository extends BaseRepository implements MaterialRepositoryInterface
{
    public function __construct(Material $model)
    {
        parent::__construct($model);
    }

    public function findByItemCode(string $code): ?Material
    {
        return $this->model->newQuery()->where('item_code', $code)->first();
    }
}
```

### 4. Create the Service
Create `app/Services/MaterialService.php`:
```php
<?php

namespace App\Services;

use App\Contracts\MaterialRepositoryInterface;
use App\Models\Material;

class MaterialService extends BaseService
{
    public function __construct(
        protected MaterialRepositoryInterface $repository
    ) {}

    public function createMaterial(array $attributes): Material
    {
        return $this->transaction(function () use ($attributes) {
            return $this->repository->create($attributes);
        });
    }
}
```

### 5. Register IoC Binding in `AppServiceProvider`
In `app/Providers/AppServiceProvider.php`:
```php
public function register(): void
{
    $this->app->bind(
        \App\Contracts\MaterialRepositoryInterface::class,
        \App\Repositories\MaterialRepository::class,
    );
}
```

### 6. Create Form Request and Resource
```bash
php artisan make:request StoreMaterialRequest
php artisan make:resource MaterialResource
```

### 7. Implement the Controller
```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMaterialRequest;
use App\Http\Resources\MaterialResource;
use App\Services\MaterialService;

class MaterialController extends Controller
{
    public function __construct(
        protected MaterialService $service
    ) {}

    public function store(StoreMaterialRequest $request)
    {
        $material = $this->service->createMaterial($request->validated());

        return new MaterialResource($material);
    }
}
```

---

## ⚡ Built-in Base Classes & Helpers

### 1. `BaseRepository` (`app/Repositories/BaseRepository.php`)
Every repository inheriting `BaseRepository` automatically receives:
- `all(array $columns = ['*'])`: Collection of all records
- `paginate(int $perPage = 15, array $columns = ['*'])`: Paginated records
- `findById(int|string $id, array $columns = ['*'])`: Find record by ID or return null
- `findOrFail(int|string $id, array $columns = ['*'])`: Find record by ID or fail
- `create(array $attributes)`: Create a new record
- `update(int|string $id, array $attributes)`: Update record by ID
- `delete(int|string $id)`: Delete record by ID
- `getModel()`: Retrieve the underlying Eloquent model

### 2. `BaseService` (`app/Services/BaseService.php`)
Provides database transaction wrapping:
```php
$result = $this->transaction(function () {
    // Operations executed atomically within a DB transaction
});
```

### 3. `ApiResponseTrait` (`app/Traits/ApiResponseTrait.php`)
Provides standardized JSON responses:
```php
return $this->successResponse($data, 'Material registered successfully', 201);
return $this->errorResponse('Invalid stock level', 422, $validationErrors);
```

### 4. `Filterable` (`app/Traits/Filterable.php`)
Add this trait to any model to enable clean dynamic query filtering:
```php
$materials = Material::filter($request)->paginate(15);
```

---

## 🔍 Quality Assurance & Standards

Before committing changes, execute the full test and analysis pipeline:

```bash
# 1. Format code with Laravel Pint
composer lint

# 2. Run static analysis (PHPStan Level strict)
composer types:check

# 3. Run automated tests (Pest / PHPUnit)
php artisan test
```
