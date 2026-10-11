<?php

use App\Http\Controllers\Api\App\Closing;
use Illuminate\Support\Facades\Route;

/*
| OA5 closing routes. Included from routes/api.php inside the tenant `accounting` prefix group (auth + tenant context already applied).
| Every route declares access:<permission>,module=ACCOUNTING_REPORTING,feature=<feature>; the central resolver is the only authority.
*/
$rp = fn (string $permission, string $feature = 'PERIOD_CLOSING') => "access:{$permission},module=ACCOUNTING_REPORTING,feature={$feature}";

// Readiness, policy, checklist, history
Route::get('closing/overview', [Closing\ClosingController::class, 'dashboard'])->middleware($rp('accounting.period.view'));
Route::get('closing/history', [Closing\ClosingController::class, 'events'])->middleware($rp('accounting.period.view'));
Route::get('closing/policy', [Closing\ClosingPolicyController::class, 'show'])->middleware($rp('accounting.period.view'));
Route::put('closing/policy', [Closing\ClosingPolicyController::class, 'update'])->middleware($rp('accounting.closing.policy.manage'));
Route::get('closing/checklist-templates', [Closing\ClosingPolicyController::class, 'templates'])->middleware($rp('accounting.period.view'));
Route::post('closing/checklist-templates', [Closing\ClosingPolicyController::class, 'storeTemplate'])->middleware($rp('accounting.closing.policy.manage'));
Route::patch('closing/checklist-templates/{template}', [Closing\ClosingPolicyController::class, 'updateTemplate'])->middleware($rp('accounting.closing.policy.manage'));
Route::delete('closing/checklist-templates/{template}', [Closing\ClosingPolicyController::class, 'destroyTemplate'])->middleware($rp('accounting.closing.policy.manage'));

// Period closing workflow
Route::get('closing/periods/{period}/readiness', [Closing\ClosingController::class, 'evaluate'])->middleware($rp('accounting.period.view'));
Route::get('closing/periods/{period}/checklist', [Closing\ClosingController::class, 'showChecklist'])->middleware($rp('accounting.period.view'));
Route::post('closing/periods/{period}/checklist/{code}/complete', [Closing\ClosingController::class, 'completeItem'])->middleware($rp('accounting.closing.checklist.manage'));
Route::post('closing/periods/{period}/checklist/{code}/waive', [Closing\ClosingController::class, 'waiveItem'])->middleware($rp('accounting.closing.checklist.manage'));
Route::post('closing/periods/{period}/checklist/{code}/reset', [Closing\ClosingController::class, 'resetItem'])->middleware($rp('accounting.closing.checklist.manage'));
Route::post('closing/periods/{period}/pre-close', [Closing\ClosingController::class, 'preClose'])->middleware($rp('accounting.period.preclose'));
Route::post('closing/periods/{period}/soft-close', [Closing\ClosingController::class, 'softClose'])->middleware($rp('accounting.period.soft_close'));
Route::post('closing/periods/{period}/hard-close', [Closing\ClosingController::class, 'hardClose'])->middleware($rp('accounting.period.hard_close'));
Route::post('closing/periods/{period}/reopen', [Closing\ClosingController::class, 'reopen'])->middleware($rp('accounting.period.reopen'));

// Year-end closing (the literal paths come before the {fiscalYear} ones)
Route::get('year-end', [Closing\YearEndController::class, 'index'])->middleware($rp('accounting.year_end.view'));
Route::get('year-end/rule-status', [Closing\YearEndController::class, 'ruleStatus'])->middleware($rp('accounting.year_end.view'));
Route::post('year-end/rule-defaults', [Closing\YearEndController::class, 'applyRuleDefaults'])->middleware($rp('accounting.posting_rule.manage'));
Route::get('year-end/{fiscalYear}', [Closing\YearEndController::class, 'show'])->middleware($rp('accounting.year_end.view'));
Route::get('year-end/{fiscalYear}/preview', [Closing\YearEndController::class, 'preview'])->middleware($rp('accounting.year_end.view'));
Route::post('year-end/{fiscalYear}/run', [Closing\YearEndController::class, 'run'])->middleware($rp('accounting.year_end.run'));
Route::post('year-end/{fiscalYear}/reverse', [Closing\YearEndController::class, 'reverse'])->middleware($rp('accounting.year_end.run'));
