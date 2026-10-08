<?php

namespace App\Http\Controllers\Api\App\Expense;

use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Services\ExpenseCategoryService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Expense categories. Thin: validation here, rules in ExpenseCategoryService. */
class ExpenseCategoryController extends AppController
{
    public function __construct(TenantContext $context, private readonly ExpenseCategoryService $categories)
    {
        parent::__construct($context);
    }

    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate(['status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])], 'q' => ['nullable', 'string', 'max:100']]);

        return response()->json(['data' => ExpenseCategory::query()->with('account')
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filter['q'] ?? null, function ($q, $v) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($v)).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(code) like ?', [$like])->orWhereRaw('lower(name) like ?', [$like]));
            })->orderBy('code')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->categories->create($this->payload($request, true)), 201);
    }

    public function update(Request $request, ExpenseCategory $category): JsonResponse
    {
        return response()->json($this->categories->update($category, $this->payload($request, false)));
    }

    public function status(Request $request, ExpenseCategory $category): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['ACTIVE', 'INACTIVE'])]]);

        return response()->json($this->categories->setStatus($category, $data['status']));
    }

    public function destroy(ExpenseCategory $category): JsonResponse
    {
        $this->categories->delete($category);

        return response()->json(null, 204);
    }

    public function applyDefaults(): JsonResponse
    {
        return response()->json(['created' => collect($this->categories->applyDefaults())->pluck('code')->all()], 201);
    }

    private function payload(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'code' => [$req, 'string', 'max:30', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\-]*$/'], 'name' => [$req, 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:255'],
            'account_id' => ['nullable', 'uuid'], 'account_role' => ['nullable', 'string', 'max:40'],
        ]);
    }
}
