<?php

namespace Tests\Support;

use App\Domain\Accounting\Services\OperationalSetupService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Payables\Models\Vendor;
use App\Domain\Payables\Services\PaymentTermService;
use App\Domain\Payables\Services\VendorService;

/** Test helpers for the OA2 modules; combine with AccountingFixtures and Fixtures. */
trait PayablesFixtures
{
    protected const AP = '/api/v1/app/accounting';

    /** An accounting tenant that has the standard OA2 posting rules published from the start of its fiscal year and the standard payment terms. */
    protected function payablesTenant(string $code = 'alpha', array $profile = []): Tenant
    {
        $tenant = $this->accountingTenant($code, profile: $profile);
        $this->inTenant($tenant, function () {
            app(OperationalSetupService::class)->applyDefaults('2026-01-01');
            app(PaymentTermService::class)->applyDefaults();
        });

        return $tenant;
    }

    protected function vendor(Tenant $tenant, string $code = 'V1', array $attributes = []): Vendor
    {
        return $this->inTenant($tenant, fn () => app(VendorService::class)->create($attributes + ['code' => $code, 'name' => "Vendor {$code}"]));
    }

    /** @return array<string,mixed> a one-line invoice body (amount 1.000.000 to the general expense account by role unless told otherwise) */
    protected function invoiceBody(Vendor $vendor, array $override = []): array
    {
        return $override + [
            'vendor_id' => $vendor->id, 'vendor_invoice_number' => 'INV-'.substr(uniqid(), -6), 'document_date' => '2026-03-10', 'posting_date' => '2026-03-10',
            'description' => 'Tagihan layanan', 'lines' => [['description' => 'Jasa konsultasi', 'amount' => '1000000']],
        ];
    }

    /** A bearer token for a new member of $tenant holding exactly $permissions (null = all); use with $this->as($token) so several users can alternate in one test. */
    protected function memberToken(Tenant $tenant, ?array $permissions = null, string $scope = 'TENANT'): string
    {
        [$user] = $this->member($tenant, $permissions, scope: $scope);

        return $this->tenantToken($user, $tenant);
    }
}
