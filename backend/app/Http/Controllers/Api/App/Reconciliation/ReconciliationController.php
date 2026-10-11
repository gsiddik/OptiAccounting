<?php

namespace App\Http\Controllers\Api\App\Reconciliation;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Support\CsvExporter;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Reconciliation\Models\ReconciliationRun;
use App\Domain\Reconciliation\Services\ReconciliationEngine;
use App\Domain\Reconciliation\Services\ReconciliationExceptionService;
use App\Domain\Reconciliation\Services\ReconciliationResult;
use App\Domain\Reconciliation\Services\ReconciliationRunService;
use App\Domain\Shared\DomainException;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The generic reconciliation routes (OA5 batch F): the live overview of every reconciliation the tenant's modules allow, one reconciliation
 * with its component detail, an explicit run that keeps the evidence, the run history and the CSV export. Thin: the engine decides every
 * figure, so screen, run and export cannot disagree. A type whose module is not entitled answers 403 MODULE_NOT_ENTITLED and exposes nothing.
 */
class ReconciliationController extends AppController
{
    public function __construct(
        TenantContext $context,
        private readonly ReconciliationEngine $engine,
        private readonly ReconciliationRunService $runs,
        private readonly ReconciliationExceptionService $exceptions,
        private readonly AuditService $audit,
    ) {
        parent::__construct($context);
    }

    public function overview(Request $request): JsonResponse
    {
        [$asOf] = $this->cutoff($request);

        return response()->json($this->overviewOf($asOf));
    }

    public function show(Request $request, string $type): JsonResponse
    {
        $type = $this->type($type);
        [$asOf] = $this->cutoff($request);

        return response()->json($this->engine->compute($type, $asOf)->toArray());
    }

    /** Compute, keep the run as evidence and update the exception of this (type, as_of). */
    public function run(Request $request, string $type): JsonResponse
    {
        $type = $this->type($type);
        [$asOf, $periodId] = $this->cutoff($request, $request->all());
        ['run' => $run, 'exception' => $exception, 'result' => $result] = $this->runs->run($type, $asOf, ReconciliationRun::MANUAL, $periodId);

        return response()->json([
            'run' => $this->runs->present($run->load('period')), 'result' => $result->toArray(),
            'exception' => $exception === null ? null : $this->exceptions->present($exception->load(['period', 'owner'])),
        ], 201);
    }

    public function runs(Request $request): JsonResponse
    {
        $filter = $request->validate([
            'type' => ['nullable', 'string', 'max:20'], 'status' => ['nullable', Rule::in([ReconciliationResult::MATCHED, ReconciliationResult::MISMATCH, ReconciliationResult::INCOMPLETE, ReconciliationResult::NOT_APPLICABLE])],
            'purpose' => ['nullable', Rule::in(ReconciliationRun::PURPOSES)], 'as_of_from' => ['nullable', 'date_format:Y-m-d'], 'as_of_to' => ['nullable', 'date_format:Y-m-d'], 'per_page' => ['nullable', 'integer', 'between:1,200'],
        ]);
        if (isset($filter['type'])) {
            $filter['type'] = ReconciliationEngine::typeOfSlug($filter['type']) ?? throw new DomainException('Unknown reconciliation type.', 'RECONCILIATION_TYPE_UNKNOWN', 422, ['field' => 'type']);
        }

        return response()->json($this->runs->query($filter)->paginate($filter['per_page'] ?? 50)->through(fn (ReconciliationRun $run) => $this->runs->present($run)));
    }

    /** One reconciliation as CSV: the headline row, then its components. Same engine call, tenant and data scope as the screen; audited. */
    public function export(Request $request, string $type): StreamedResponse
    {
        $type = $this->type($type);
        [$asOf] = $this->cutoff($request);

        return $this->csv(ReconciliationEngine::slug($type), $asOf, [$this->engine->compute($type, $asOf)]);
    }

    /** Every available reconciliation as CSV (the rows of the overview). */
    public function exportAll(Request $request): StreamedResponse
    {
        [$asOf] = $this->cutoff($request);

        return $this->csv('overview', $asOf, $this->results($asOf));
    }

    // ------------------------------------------------------------------------------------------------ internals

    /** @return array{as_of:string,data:list<array<string,mixed>>,complete:bool} */
    private function overviewOf(string $asOf): array
    {
        $results = $this->results($asOf);

        return ['as_of' => $asOf, 'data' => array_map(fn (ReconciliationResult $r) => $r->toArray(), $results), 'complete' => collect($results)->every(fn (ReconciliationResult $r) => $r->complete)];
    }

    /** @return list<ReconciliationResult> the results of the types the tenant's modules allow, in the order of ReconciliationResult::TYPES */
    private function results(string $asOf): array
    {
        $all = $this->engine->computeAll($asOf);

        return array_values(array_map(fn (string $type) => $all[$type], $this->engine->availableTypes()));
    }

    /** @param list<ReconciliationResult> $results */
    private function csv(string $what, string $asOf, array $results): StreamedResponse
    {
        $this->audit->record('accounting.report.exported', 'reconciliation', $what, null, [
            'as_of' => $asOf, 'results' => array_map(fn (ReconciliationResult $r) => ['type' => $r->type, 'status' => $r->status, 'difference' => $r->difference], $results),
        ]);

        return CsvExporter::stream("rekonsiliasi_{$what}_{$asOf}.csv", ['Jenis', 'Tanggal', 'Status', 'Komponen', 'Keterangan', 'Saldo sumber', 'Saldo buku besar', 'Selisih', 'Toleransi', 'Lengkap'], (function () use ($results) {
            foreach ($results as $r) {
                yield [$r->type, $r->asOf, $r->status, '', implode(' ', $r->notes), $this->number($r->sourceBalance), $this->number($r->glBalance), $this->number($r->difference), CsvExporter::number($r->tolerance), $r->complete ? 'Ya' : 'Tidak'];
                foreach ($r->components as $c) {
                    yield [$r->type, $r->asOf, $c['status'], $c['code'], $c['label'], $this->number($c['source_balance']), $this->number($c['gl_balance']), $this->number($c['difference']), '', ''];
                }
            }
        })());
    }

    private function number(?string $value): mixed
    {
        return $value === null ? '' : CsvExporter::number($value);
    }

    /** The type of a URL slug, if the tenant's modules allow it (otherwise it exposes nothing). */
    private function type(string $slug): string
    {
        $type = ReconciliationEngine::typeOfSlug($slug) ?? throw new DomainException('Unknown reconciliation type.', 'RECONCILIATION_TYPE_UNKNOWN', 404);
        if (! $this->engine->available($type)) {
            throw new DomainException('This reconciliation is not available to the organization.', 'MODULE_NOT_ENTITLED', 403, ['type' => $type]);
        }

        return $type;
    }

    /**
     * The cut-off date of the request: an explicit `as_of`, or the end date of `period_id` (a period is just its end date), or today's business
     * date of the tenant when neither is sent. The period must be the tenant's own.
     *
     * @param  array<string,mixed>|null  $input  the body of a POST (a GET reads the query string)
     * @return array{0:string,1:?string} the date and the period id when one was named
     */
    private function cutoff(Request $request, ?array $input = null): array
    {
        $rules = ['as_of' => ['nullable', 'date_format:Y-m-d'], 'period_id' => ['nullable', 'uuid']];
        $data = $input === null ? $request->validate($rules) : validator($input, $rules)->validate();
        if (! empty($data['period_id'])) {
            $period = AccountingPeriod::query()->find($data['period_id']) ?? throw new DomainException('Accounting period not found.', 'PERIOD_NOT_FOUND', 404);
            $end = $period->end_date->toDateString();
            if (! empty($data['as_of']) && $data['as_of'] !== $end) {
                throw new DomainException('as_of and period_id name different cut-off dates; send one of them.', 'AS_OF_PERIOD_CONFLICT', 422, ['field' => 'as_of']);
            }

            return [$end, $period->id];
        }

        return [$data['as_of'] ?? Tenant::query()->findOrFail($this->tenantId())->businessDate(), null];
    }
}
