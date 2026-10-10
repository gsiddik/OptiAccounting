<?php

namespace App\Http\Controllers\Api\App\Currency;

use App\Domain\Currency\Models\Currency;
use App\Domain\Currency\Services\CurrencyService;
use App\Domain\Currency\Services\FxSetupService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The foreign currencies a tenant uses, and the default posting rules of the foreign settlement events. Thin: validation here, every rule in the services. */
class CurrencyController extends AppController
{
    public function __construct(TenantContext $context, private readonly CurrencyService $currencies, private readonly FxSetupService $setup)
    {
        parent::__construct($context);
    }

    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate(['status' => ['nullable', Rule::in([Currency::ACTIVE, Currency::INACTIVE])], 'q' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'between:1,200']]);

        $page = $this->currencies->query($filter)->paginate($filter['per_page'] ?? 50);
        $page->getCollection()->transform(fn (Currency $c) => $this->currencies->load($c));

        return response()->json($page);
    }

    public function show(Currency $currency): JsonResponse
    {
        return response()->json($this->currencies->load($currency));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->currencies->create($this->payload($request, true), $request->user()), 201);
    }

    public function update(Request $request, Currency $currency): JsonResponse
    {
        return response()->json($this->currencies->update($currency, $this->payload($request, false)));
    }

    public function activate(Currency $currency): JsonResponse
    {
        return response()->json($this->currencies->setStatus($currency, Currency::ACTIVE));
    }

    public function deactivate(Currency $currency): JsonResponse
    {
        return response()->json($this->currencies->setStatus($currency, Currency::INACTIVE));
    }

    public function destroy(Currency $currency): JsonResponse
    {
        $this->currencies->delete($currency);

        return response()->json(null, 204);
    }

    public function setupStatus(): JsonResponse
    {
        return response()->json($this->setup->status());
    }

    public function applyDefaults(Request $request): JsonResponse
    {
        $data = $request->validate(['effective_from' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json($this->setup->applyDefaults($data['effective_from'] ?? null), 201);
    }

    /** @return array<string,mixed> */
    private function payload(Request $request, bool $creating): array
    {
        return $request->validate([
            'code' => [$creating ? 'required' : 'prohibited', 'string', 'size:3'], 'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'symbol' => ['nullable', 'string', 'max:10'], 'decimal_places' => ['sometimes'],
        ]);
    }
}
