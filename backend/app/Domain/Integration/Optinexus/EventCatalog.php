<?php

namespace App\Domain\Integration\Optinexus;

use App\Domain\Integration\Services\OutboxPublisher;

/**
 * The `optiaccounting.*` event types this application publishes to OptiNexus, with the payload schema registered in the
 * OptiNexus Event Catalog (`required` keys and top-level `type`s only, which is what OptiNexus validates). Later phases
 * add their events here additively; an event that is not listed is never sent.
 */
final class EventCatalog
{
    /** @return list<array{event_key:string,name:string,description:string,schema_version:string,payload_schema:array<string,mixed>}> */
    public static function all(): array
    {
        $p = OutboxPublisher::EVENT_PREFIX;

        return [
            [
                'event_key' => $p.'tenant.linked', 'name' => 'Tenant linked', 'schema_version' => '1',
                'description' => 'An OptiNexus tenant was linked to a local OptiAccounting organization on its first sign-in.',
                'payload_schema' => ['required' => ['optinexus_tenant_id', 'code'], 'properties' => ['optinexus_tenant_id' => ['type' => 'string'], 'code' => ['type' => 'string']]],
            ],
            [
                'event_key' => $p.'membership.provisioned', 'name' => 'Membership provisioned', 'schema_version' => '1',
                'description' => 'A user was given a membership of a linked organization on their first sign-in.',
                'payload_schema' => ['required' => ['user_subject', 'membership_id'], 'properties' => ['user_subject' => ['type' => 'string'], 'membership_id' => ['type' => 'string']]],
            ],
            [
                'event_key' => $p.'membership.deactivated', 'name' => 'Membership deactivated', 'schema_version' => '1',
                'description' => 'A membership was deactivated because OptiNexus revoked the user\'s access to the organization.',
                'payload_schema' => ['required' => ['user_subject', 'reason'], 'properties' => ['user_subject' => ['type' => 'string'], 'reason' => ['type' => 'string']]],
            ],
            [
                'event_key' => $p.'journal.posted', 'name' => 'Journal posted', 'schema_version' => '1',
                'description' => 'A journal was posted to the general ledger of an organization.',
                'payload_schema' => ['required' => ['journal_id', 'journal_number', 'posting_date'], 'properties' => ['journal_id' => ['type' => 'string'], 'journal_number' => ['type' => 'string'], 'journal_type' => ['type' => 'string'], 'posting_date' => ['type' => 'string'], 'total' => ['type' => 'string'], 'currency' => ['type' => 'string']]],
            ],
            [
                'event_key' => $p.'journal.reversed', 'name' => 'Journal reversed', 'schema_version' => '1',
                'description' => 'A posted journal was reversed by a new journal.',
                'payload_schema' => ['required' => ['journal_id', 'reversal_id'], 'properties' => ['journal_id' => ['type' => 'string'], 'journal_number' => ['type' => 'string'], 'reversal_id' => ['type' => 'string'], 'reversal_number' => ['type' => 'string'], 'posting_date' => ['type' => 'string']]],
            ],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_column(self::all(), 'event_key');
    }
}
