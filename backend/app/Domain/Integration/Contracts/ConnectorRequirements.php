<?php

namespace App\Domain\Integration\Contracts;

use App\Domain\Shared\DomainException;

/**
 * Optional capability of an IntegrationAdapter whose connections need more than the generic configuration: references to the tenant's own
 * accounting setup (`settings`) and entitlements beyond the integration module. The generic core asks the adapter instead of knowing any
 * product: ConnectionService validates the settings and refuses to activate a connection that lacks them or is not entitled, and
 * EventProcessor leaves a source's events untouched while the tenant is not entitled (nothing reaches the books, nothing is flagged as an error).
 *
 * All methods run in the tenant context of the caller.
 */
interface ConnectorRequirements
{
    /**
     * Validate the settings that were sent (the keys present, nothing else) and return them normalised.
     *
     * @param  array<string,string>  $settings
     * @return array<string,string>
     *
     * @throws DomainException an unknown key, or a reference that is not a usable record of this tenant
     */
    public function cleanSettings(array $settings): array;

    /**
     * @param  array<string,mixed>  $settings  the stored settings
     * @return list<string> the setting keys an ACTIVE connection needs and these lack
     */
    public function missingSettings(array $settings): array;

    /** @return list<string> why the tenant may not run this connector now (empty = entitled) */
    public function entitlementProblems(): array;
}
