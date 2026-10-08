<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OA2 batch I (prepared early): account roles learn two things the posting rules need.
 *  - binding: MAPPED roles resolve through the tenant's account mapping (OA1); DOCUMENT roles take the account from the source
 *    document (the cash/bank account chosen on a payment, the classification on a line) and need no mapping.
 *  - restricted_events: roles that belong to a subledger (accounts payable) may appear only in the rules of that subledger's events,
 *    so no rule can move the control account without its subledger knowing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_roles', function (Blueprint $table) {
            $table->string('binding', 10)->default('MAPPED');
            $table->jsonb('restricted_events')->nullable();
        });
        DB::statement("ALTER TABLE account_roles ADD CONSTRAINT account_roles_binding_check CHECK (binding IN ('MAPPED','DOCUMENT'))");

        DB::table('account_roles')->where('code', 'ACCOUNTS_PAYABLE')->update([
            'restricted_events' => json_encode(['AP_INVOICE_RECOGNIZED', 'VENDOR_PAYMENT', 'EXPENSE_RECOGNIZED']),
        ]);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE account_roles DROP CONSTRAINT IF EXISTS account_roles_binding_check');
        Schema::table('account_roles', function (Blueprint $table) {
            $table->dropColumn(['binding', 'restricted_events']);
        });
    }
};
