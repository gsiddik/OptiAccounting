<?php

namespace App\Http\Controllers\Api\App\Currency;

use App\Domain\Currency\Models\ExchangeRate;
use App\Domain\Currency\Services\ExchangeRateResolver;
use App\Domain\Currency\Services\ExchangeRateService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Exchange rates into the functional currency. A rate is a fact: entered, withdrawn or deleted while unused, never edited. */
class ExchangeRateController extends AppController
{
    public function __construct(TenantContext $context, private readonly ExchangeRateService $rates, private readonly ExchangeRateResolver $resolver)
    {
        parent::__construct($context);
    }

    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate([
            'currency' => ['nullable', 'regex:/^[A-Z]{3}$/'], 'rate_type' => ['nullable', Rule::in(ExchangeRate::TYPES)], 'status' => ['nullable', Rule::in([ExchangeRate::ACTIVE, ExchangeRate::INACTIVE])],
            'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d'], 'per_page' => ['nullable', 'integer', 'between:1,200'],
        ]);

        return response()->json($this->rates->query($filter)->paginate($filter['per_page'] ?? 50));
    }

    public function show(ExchangeRate $rate): JsonResponse
    {
        return response()->json($this->rates->load($rate));
    }

    /** The rate a document of that currency would use on a date, or the reason there is none. */
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate(['currency' => ['required', 'regex:/^[A-Z]{3}$/'], 'date' => ['required', 'date_format:Y-m-d'], 'rate_type' => ['nullable', Rule::in(ExchangeRate::TYPES)]]);
        $rate = $this->resolver->resolve($data['currency'], $data['date'], $data['rate_type'] ?? null);

        return response()->json(['currency' => $rate->currency, 'functional_currency' => $rate->functionalCurrency, 'rate' => $rate->rateString(), 'rate_id' => $rate->id, 'effective_date' => $rate->effectiveDate, 'rate_type' => $rate->type, 'source' => $rate->source]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_currency' => ['required', 'regex:/^[A-Z]{3}$/'], 'rate' => ['present'], 'effective_date' => ['required', 'date_format:Y-m-d'],
            'rate_type' => ['nullable', Rule::in(ExchangeRate::TYPES)], 'source' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($this->rates->create($data, $request->user()), 201);
    }

    public function update(Request $request, ExchangeRate $rate): JsonResponse
    {
        // the immutable facts are passed through on purpose: the service refuses them with a clear reason
        $data = $request->validate(['source' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:255'], 'rate' => ['sometimes'], 'from_currency' => ['sometimes'], 'effective_date' => ['sometimes'], 'rate_type' => ['sometimes']]);

        return response()->json($this->rates->update($rate, $data));
    }

    public function activate(ExchangeRate $rate): JsonResponse
    {
        return response()->json($this->rates->setStatus($rate, ExchangeRate::ACTIVE));
    }

    public function deactivate(ExchangeRate $rate): JsonResponse
    {
        return response()->json($this->rates->setStatus($rate, ExchangeRate::INACTIVE));
    }

    public function destroy(ExchangeRate $rate): JsonResponse
    {
        $this->rates->delete($rate);

        return response()->json(null, 204);
    }
}
