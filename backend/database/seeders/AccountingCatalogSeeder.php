<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Platform catalogs of the Accounting Core (OA1): dimension types, account roles, accounting event types and one
 * minimal COA template. Production-safe and idempotent; rows that already exist are never rewritten, so operator edits survive.
 */
class AccountingCatalogSeeder extends Seeder
{
    private const DIMENSION_TYPES = [
        ['BRANCH', 'Cabang', 'INTERNAL'], ['BUSINESS_UNIT', 'Unit bisnis', 'INTERNAL'], ['COST_CENTER', 'Pusat biaya', 'INTERNAL'],
    ];

    private const ACCOUNT_ROLES = [
        'CASH' => 'Kas', 'BANK' => 'Bank', 'ACCOUNTS_RECEIVABLE' => 'Piutang usaha', 'ACCOUNTS_PAYABLE' => 'Utang usaha',
        'INVENTORY_ASSET' => 'Persediaan', 'EXPENSE' => 'Beban (umum)', 'REVENUE' => 'Pendapatan (umum)',
        'TAX_RECEIVABLE' => 'Pajak dibayar di muka / PPN masukan', 'TAX_PAYABLE' => 'Utang pajak / PPN keluaran', 'RETAINED_EARNINGS' => 'Laba ditahan',
    ];

    /** OA2: roles whose account is named by the source document, not by a tenant mapping (code => name). */
    private const DOCUMENT_ROLES = [
        'CASH_BANK_ACCOUNT' => 'Akun kas/bank pada dokumen (dari akun kas atau bank yang dipilih)',
        'DOCUMENT_ACCOUNT' => 'Akun lawan pada baris dokumen (klasifikasi akun di dokumen)',
    ];

    /** code => [name, description, components]. The components are the only amount keys a posting rule line may use. */
    private const EVENT_TYPES = [
        'EXPENSE_RECOGNIZED' => ['Beban diakui', 'Komponen: net, tax, total.', ['net', 'tax', 'total']],
        'AP_INVOICE_RECOGNIZED' => ['Faktur vendor diakui', 'Komponen: net, tax, total. Diaktifkan oleh OA2.', ['net', 'tax', 'total']],
        'VENDOR_PAYMENT' => ['Pembayaran vendor', 'Komponen: amount. Diaktifkan oleh OA2.', ['amount']],
        'AR_INVOICE_RECOGNIZED' => ['Faktur pelanggan diakui', 'Komponen: net, tax, total. Diaktifkan oleh OA3.', ['net', 'tax', 'total']],
        'CUSTOMER_RECEIPT' => ['Penerimaan pelanggan', 'Komponen: amount. Diaktifkan oleh OA3.', ['amount']],
        'EXPENSE_PAID' => ['Beban dibayar langsung', 'Komponen: net, tax, total. Beban yang langsung dibayar dari kas/bank (OA2).', ['net', 'tax', 'total']],
        'CASH_PAYMENT' => ['Pembayaran kas/bank', 'Komponen: amount. Pembayaran di luar utang usaha (OA2).', ['amount']],
        'CASH_RECEIPT' => ['Penerimaan kas/bank', 'Komponen: amount. Penerimaan di luar piutang usaha (OA2).', ['amount']],
    ];

    /** code, name, parent, type, postable, control, role */
    private const TEMPLATE = [
        ['1000', 'Aset', null, 'ASSET', false, false, null],
        ['1100', 'Aset Lancar', '1000', 'ASSET', false, false, null],
        ['1110', 'Kas', '1100', 'ASSET', true, false, 'CASH'],
        ['1120', 'Bank', '1100', 'ASSET', true, false, 'BANK'],
        ['1130', 'Piutang Usaha', '1100', 'ASSET', true, true, 'ACCOUNTS_RECEIVABLE'],
        ['1140', 'Persediaan', '1100', 'ASSET', true, false, 'INVENTORY_ASSET'],
        ['1150', 'Pajak Dibayar di Muka (PPN Masukan)', '1100', 'ASSET', true, false, 'TAX_RECEIVABLE'],
        ['1160', 'Biaya Dibayar di Muka', '1100', 'ASSET', true, false, null],
        ['1200', 'Aset Tetap', '1000', 'ASSET', false, false, null],
        ['1210', 'Tanah dan Bangunan', '1200', 'ASSET', true, false, null],
        ['1220', 'Kendaraan', '1200', 'ASSET', true, false, null],
        ['1230', 'Peralatan', '1200', 'ASSET', true, false, null],
        ['1290', 'Akumulasi Penyusutan', '1200', 'ASSET', true, false, null, 'CREDIT'],
        ['2000', 'Kewajiban', null, 'LIABILITY', false, false, null],
        ['2100', 'Kewajiban Jangka Pendek', '2000', 'LIABILITY', false, false, null],
        ['2110', 'Utang Usaha', '2100', 'LIABILITY', true, true, 'ACCOUNTS_PAYABLE'],
        ['2120', 'Utang Pajak (PPN Keluaran)', '2100', 'LIABILITY', true, false, 'TAX_PAYABLE'],
        ['2130', 'Utang Gaji', '2100', 'LIABILITY', true, false, null],
        ['2140', 'Utang Lain-lain', '2100', 'LIABILITY', true, false, null],
        ['2200', 'Kewajiban Jangka Panjang', '2000', 'LIABILITY', false, false, null],
        ['2210', 'Utang Bank Jangka Panjang', '2200', 'LIABILITY', true, false, null],
        ['3000', 'Ekuitas', null, 'EQUITY', false, false, null],
        ['3100', 'Modal Disetor', '3000', 'EQUITY', true, false, null],
        ['3200', 'Laba Ditahan', '3000', 'EQUITY', true, false, 'RETAINED_EARNINGS'],
        ['4000', 'Pendapatan', null, 'REVENUE', false, false, null],
        ['4100', 'Pendapatan Usaha', '4000', 'REVENUE', true, false, 'REVENUE'],
        ['4200', 'Pendapatan Lain-lain', '4000', 'REVENUE', true, false, null],
        ['5000', 'Harga Pokok Penjualan', null, 'EXPENSE', false, false, null],
        ['5100', 'Harga Pokok Penjualan', '5000', 'EXPENSE', true, false, null],
        ['6000', 'Beban Operasional', null, 'EXPENSE', false, false, null],
        ['6100', 'Beban Gaji', '6000', 'EXPENSE', true, false, null],
        ['6200', 'Beban Sewa', '6000', 'EXPENSE', true, false, null],
        ['6300', 'Beban Listrik, Air dan Telepon', '6000', 'EXPENSE', true, false, null],
        ['6400', 'Beban Perawatan Kendaraan', '6000', 'EXPENSE', true, false, null],
        ['6500', 'Beban BBM', '6000', 'EXPENSE', true, false, null],
        ['6600', 'Beban Penyusutan', '6000', 'EXPENSE', true, false, null],
        ['6900', 'Beban Umum dan Administrasi', '6000', 'EXPENSE', true, false, 'EXPENSE'],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            $now = now();

            foreach (self::DIMENSION_TYPES as $i => [$code, $name, $kind]) {
                DB::table('dimension_types')->insertOrIgnore(['code' => $code, 'name' => $name, 'kind' => $kind, 'status' => 'ACTIVE', 'sort_order' => ($i + 1) * 10, 'created_at' => $now, 'updated_at' => $now]);
            }

            $order = 10;
            foreach (self::ACCOUNT_ROLES as $code => $name) {
                DB::table('account_roles')->insertOrIgnore(['code' => $code, 'name' => $name, 'status' => 'ACTIVE', 'sort_order' => $order, 'created_at' => $now, 'updated_at' => $now]);
                $order += 10;
            }

            foreach (self::DOCUMENT_ROLES as $code => $name) {
                DB::table('account_roles')->insertOrIgnore(['code' => $code, 'name' => $name, 'binding' => 'DOCUMENT', 'status' => 'ACTIVE', 'sort_order' => $order, 'created_at' => $now, 'updated_at' => $now]);
                $order += 10;
            }

            $order = 10;
            foreach (self::EVENT_TYPES as $code => [$name, $description, $components]) {
                DB::table('accounting_event_types')->insertOrIgnore(['code' => $code, 'name' => $name, 'description' => $description, 'components' => json_encode($components), 'status' => 'ACTIVE', 'sort_order' => $order, 'created_at' => $now, 'updated_at' => $now]);
                $order += 10;
            }

            $this->template($now);
        });
    }

    private function template($now): void
    {
        if (DB::table('coa_templates')->where('code', 'UMUM_ID')->exists()) {
            return;
        }

        $id = (string) Str::uuid7();
        DB::table('coa_templates')->insert([
            'id' => $id, 'code' => 'UMUM_ID', 'name' => 'Umum Indonesia (UKM)', 'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now,
            'description' => 'Bagan akun ringkas untuk usaha jasa dan dagang. Salinan ini menjadi akun milik tenant; bebas diubah.',
        ]);

        foreach (self::TEMPLATE as $i => $row) {
            [$code, $name, $parent, $type, $postable, $control, $role] = $row;
            DB::table('coa_template_accounts')->insert([
                'id' => (string) Str::uuid7(), 'coa_template_id' => $id, 'code' => $code, 'name' => $name, 'parent_code' => $parent,
                'account_type' => $type, 'normal_balance' => $row[7] ?? (in_array($type, ['ASSET', 'EXPENSE'], true) ? 'DEBIT' : 'CREDIT'),
                'is_postable' => $postable, 'is_control' => $control, 'account_role' => $role, 'sort_order' => $i * 10,
            ]);
        }
    }
}
