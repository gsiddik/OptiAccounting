<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Database-level guards shared by every OA2 source document (vendor invoice, vendor payment, expense, cash transaction).
 * The services enforce the same rules; these triggers make a bypass (raw SQL, a forgotten service path) impossible.
 *
 *  - a document is deleted only while DRAFT;
 *  - status moves only along the workflow (DRAFT > SUBMITTED > APPROVED > POSTED > REVERSED, REJECTED, CANCELLED);
 *  - a POSTED document changes nothing except its one-time reversal bookkeeping (the columns the table names);
 *    REVERSED and CANCELLED are terminal;
 *  - lines belong to a draft document only.
 */
final class DocumentGuards
{
    public static function installFunctions(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION oa2_document_guard() RETURNS trigger AS $$
            DECLARE
                allowed text[];
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'DRAFT' THEN
                        RAISE EXCEPTION 'only a draft % can be deleted', TG_TABLE_NAME USING ERRCODE = '23514';
                    END IF;
                    RETURN OLD;
                END IF;

                IF OLD.status IN ('REVERSED', 'CANCELLED') THEN
                    RAISE EXCEPTION 'a % % is final and cannot change', lower(OLD.status), TG_TABLE_NAME USING ERRCODE = '23514';
                END IF;

                IF OLD.status = 'POSTED' THEN
                    IF NEW.status = 'POSTED' THEN
                        IF (to_jsonb(NEW) - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'updated_at') THEN
                            RAISE EXCEPTION 'a posted % is immutable', TG_TABLE_NAME USING ERRCODE = '23514';
                        END IF;
                    ELSIF NEW.status = 'REVERSED' THEN
                        allowed := ARRAY['status', 'updated_at'] || string_to_array(coalesce(TG_ARGV[0], ''), ',');
                        IF (to_jsonb(NEW) - allowed) IS DISTINCT FROM (to_jsonb(OLD) - allowed) THEN
                            RAISE EXCEPTION 'a posted % can only receive its reversal link', TG_TABLE_NAME USING ERRCODE = '23514';
                        END IF;
                    ELSE
                        RAISE EXCEPTION 'a posted % can only be reversed', TG_TABLE_NAME USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;

                IF NEW.status <> OLD.status AND NOT (
                    (OLD.status = 'DRAFT' AND NEW.status IN ('SUBMITTED', 'CANCELLED', 'POSTED'))
                    OR (OLD.status = 'SUBMITTED' AND NEW.status IN ('APPROVED', 'REJECTED', 'CANCELLED'))
                    OR (OLD.status = 'APPROVED' AND NEW.status IN ('POSTED', 'CANCELLED'))
                    OR (OLD.status = 'REJECTED' AND NEW.status IN ('DRAFT', 'CANCELLED'))) THEN
                    RAISE EXCEPTION '% status cannot move from % to %', TG_TABLE_NAME, OLD.status, NEW.status USING ERRCODE = '23514';
                END IF;

                IF OLD.document_number IS NOT NULL AND NEW.document_number IS DISTINCT FROM OLD.document_number THEN
                    RAISE EXCEPTION 'a document number cannot change once issued' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END; $$ LANGUAGE plpgsql
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION oa2_lines_guard() RETURNS trigger AS $$
            DECLARE
                parent_status text; parent_id uuid; owner uuid;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    parent_id := (to_jsonb(NEW) ->> TG_ARGV[1])::uuid; owner := NEW.tenant_id;
                ELSE
                    parent_id := (to_jsonb(OLD) ->> TG_ARGV[1])::uuid; owner := OLD.tenant_id;
                END IF;

                EXECUTE format('SELECT status FROM %I WHERE tenant_id = $1 AND id = $2', TG_ARGV[0]) INTO parent_status USING owner, parent_id;
                IF parent_status IS NOT NULL AND parent_status <> 'DRAFT' THEN
                    RAISE EXCEPTION 'the lines of a % document cannot change', lower(parent_status) USING ERRCODE = '23514';
                END IF;

                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION oa2_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'the history in % is append-only', TG_TABLE_NAME USING ERRCODE = '23514';
            END; $$ LANGUAGE plpgsql
            SQL);
    }

    /** @param list<string> $reversalColumns the only columns that may change when a POSTED document becomes REVERSED */
    public static function guardDocument(string $table, array $reversalColumns): void
    {
        $columns = implode(',', $reversalColumns);
        DB::unprepared("CREATE TRIGGER {$table}_guard BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION oa2_document_guard('{$columns}')");
    }

    public static function guardLines(string $table, string $parentTable, string $parentKey): void
    {
        DB::unprepared("CREATE TRIGGER {$table}_guard BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION oa2_lines_guard('{$parentTable}', '{$parentKey}')");
    }

    public static function appendOnly(string $table): void
    {
        DB::unprepared("CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION oa2_append_only()");
    }

    public static function drop(string $table): void
    {
        DB::unprepared("DROP TRIGGER IF EXISTS {$table}_guard ON {$table}");
    }
}
