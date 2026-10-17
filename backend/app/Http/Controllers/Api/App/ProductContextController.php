<?php

namespace App\Http\Controllers\Api\App;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Integration\Optinexus\ProductContext;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The products the signed-in person may open from here (the switcher) and the revalidation of their session (audit A29).
 * Every signed-in user may ask for their own: the answer is about the session's user and tenant, never about a request value.
 */
class ProductContextController extends AppController
{
    public function __construct(TenantContext $context, private readonly ProductContext $products, private readonly AuditService $audit)
    {
        parent::__construct($context);
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $this->tenantId();
        $answer = $this->products->forMember($user, $tenantId);

        if (($answer['available'] ?? false) && $answer['allowed'] === false) {
            $this->endSession($request, $tenantId, (string) $answer['deny_reason']);

            return response()->json($answer + ['session_ended' => true]);
        }

        return response()->json($answer + ['session_ended' => false]);
    }

    /** OptiNexus says this person may no longer be here: end this session (and any other for the tenant), keep the reason for the message. */
    private function endSession(Request $request, string $tenantId, string $reason): void
    {
        $user = $request->user();
        DB::table('personal_access_tokens')
            ->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->id)
            ->where('abilities', 'like', '%"tenant:'.$tenantId.'"%')->delete();
        $request->user()->currentAccessToken()?->delete();
        $this->products->forget($tenantId, (string) $user->id);
        $this->audit->record('auth.session_ended_by_optinexus', 'user', (string) $user->id, null, ['reason' => $reason], $tenantId);
    }
}
