import type { ReactNode } from 'react'
import { Link, Navigate, Route, Routes, useLocation } from 'react-router-dom'
import { Shell, type NavItem } from './components/Shell'
import { Banner, EmptyState, Loading } from './components/ui'
import { homePath, useAuth } from './lib/auth'
import { CapabilityProvider, useCapabilities } from './lib/capabilities'
import ChooseAccess from './pages/ChooseAccess'
import Login from './pages/Login'
import SsoCallback from './pages/SsoCallback'
import PlatformAudit from './pages/platform/Audit'
import Bundles from './pages/platform/Bundles'
import PlatformDashboard from './pages/platform/Dashboard'
import Modules from './pages/platform/Modules'
import Operators from './pages/platform/Operators'
import TenantDetail from './pages/platform/TenantDetail'
import Tenants from './pages/platform/Tenants'
import TenantAudit from './pages/tenant/Audit'
import TenantDashboard from './pages/tenant/Dashboard'
import Organization from './pages/tenant/Organization'
import Roles from './pages/tenant/Roles'
import Subscription from './pages/tenant/Subscription'
import Usage from './pages/tenant/Usage'
import Users from './pages/tenant/Users'
import AccountMappings from './pages/accounting/AccountMappings'
import AccountingHome from './pages/accounting/AccountingHome'
import ChartOfAccounts from './pages/accounting/ChartOfAccounts'
import FiscalCalendar from './pages/accounting/FiscalCalendar'
import GeneralLedger from './pages/accounting/GeneralLedger'
import JournalDetail from './pages/accounting/JournalDetail'
import JournalEditor from './pages/accounting/JournalEditor'
import Journals from './pages/accounting/Journals'
import OpeningBalancePage from './pages/accounting/OpeningBalance'
import PostingRules from './pages/accounting/PostingRules'
import Profile from './pages/accounting/Profile'
import TrialBalance from './pages/accounting/TrialBalance'
import ApAging from './pages/operational/ApAging'
import ApInvoiceDetail from './pages/operational/ApInvoiceDetail'
import ApInvoiceEditor from './pages/operational/ApInvoiceEditor'
import ApInvoices from './pages/operational/ApInvoices'
import ApReconciliation from './pages/operational/ApReconciliation'
import BankStatementDetail from './pages/operational/BankStatementDetail'
import BankStatements from './pages/operational/BankStatements'
import CashBankAccountDetail from './pages/operational/CashBankAccountDetail'
import CashBankAccounts from './pages/operational/CashBankAccounts'
import CashBankReconciliation from './pages/operational/CashBankReconciliation'
import CashTransactionDetail from './pages/operational/CashTransactionDetail'
import CashTransactionEditor from './pages/operational/CashTransactionEditor'
import CashTransactions from './pages/operational/CashTransactions'
import ExpenseCategories from './pages/operational/ExpenseCategories'
import ExpenseDetail from './pages/operational/ExpenseDetail'
import ExpenseEditor from './pages/operational/ExpenseEditor'
import Expenses from './pages/operational/Expenses'
import VendorPaymentDetail from './pages/operational/VendorPaymentDetail'
import VendorPaymentEditor from './pages/operational/VendorPaymentEditor'
import VendorPayments from './pages/operational/VendorPayments'
import Vendors from './pages/operational/Vendors'

// Navigation is declarative: an item shows only when the user holds its permission (cosmetic; the API enforces).
const PLATFORM_NAV: NavItem[] = [
  { to: '/platform', label: 'Dashboard', icon: 'home', end: true },
  { to: '/platform/tenants', label: 'Tenant', icon: 'building', permission: 'platform.tenant.view', group: 'Pelanggan' },
  { to: '/platform/modules', label: 'Modul & fitur', icon: 'grid', permission: 'platform.module.view', group: 'Katalog' },
  { to: '/platform/bundles', label: 'Paket', icon: 'box', permission: 'platform.bundle.view', group: 'Katalog' },
  { to: '/platform/operators', label: 'Operator & akses', icon: 'shield', permission: 'platform.user.view', group: 'Platform' },
  { to: '/platform/audit', label: 'Audit', icon: 'log', permission: 'platform.audit.view', group: 'Platform' },
]

const TENANT_NAV: NavItem[] = [
  { to: '/app', label: 'Dashboard', icon: 'home', end: true },
  { to: '/app/organisasi', label: 'Organisasi', icon: 'building', permission: 'organization.view', group: 'Pengaturan' },
  { to: '/app/pengguna', label: 'Pengguna', icon: 'users', permission: 'access.user.view', group: 'Pengaturan' },
  { to: '/app/peran', label: 'Peran & izin', icon: 'shield', permission: 'access.role.view', group: 'Pengaturan' },
  // Accounting Core: shown only while ACCOUNTING_CORE is entitled and the user holds the permission (cosmetic; the API enforces both).
  { to: '/app/akuntansi', label: 'Ringkasan', icon: 'chart', permission: 'accounting.journal.view', module: 'ACCOUNTING_CORE', group: 'Akuntansi', end: true },
  { to: '/app/akuntansi/jurnal', label: 'Jurnal', icon: 'list', permission: 'accounting.journal.view', module: 'ACCOUNTING_CORE', group: 'Akuntansi' },
  { to: '/app/akuntansi/buku-besar', label: 'Buku besar', icon: 'book', permission: 'accounting.gl.view', module: 'ACCOUNTING_CORE', group: 'Akuntansi' },
  { to: '/app/akuntansi/neraca-saldo', label: 'Neraca saldo', icon: 'scale', permission: 'accounting.trial_balance.view', module: 'ACCOUNTING_CORE', group: 'Akuntansi' },
  { to: '/app/akuntansi/saldo-awal', label: 'Saldo awal', icon: 'box', permission: 'accounting.opening_balance.view', module: 'ACCOUNTING_CORE', group: 'Akuntansi' },
  // OA2 modules. Each section needs its own module (and, for the cash/bank documents, its feature) and the user's permission (cosmetic; the API enforces).
  { to: '/app/akuntansi/vendor', label: 'Vendor', icon: 'users', permission: 'accounting.vendor.view', module: 'ACCOUNTING_AP', feature: 'VENDOR', group: 'Utang usaha' },
  { to: '/app/akuntansi/faktur-vendor', label: 'Faktur vendor', icon: 'list', permission: 'accounting.ap_invoice.view', module: 'ACCOUNTING_AP', feature: 'VENDOR_INVOICE', group: 'Utang usaha' },
  { to: '/app/akuntansi/pembayaran-vendor', label: 'Pembayaran vendor', icon: 'card', permission: 'accounting.ap_payment.view', module: 'ACCOUNTING_AP', feature: 'AP_PAYMENT', group: 'Utang usaha' },
  { to: '/app/akuntansi/umur-utang', label: 'Umur utang', icon: 'calendar', permission: 'accounting.ap_aging.view', module: 'ACCOUNTING_AP', feature: 'AP_AGING', group: 'Utang usaha' },
  { to: '/app/akuntansi/beban', label: 'Beban', icon: 'tag', permission: 'accounting.expense.view', module: 'ACCOUNTING_EXPENSE', feature: 'EXPENSE', group: 'Beban' },
  { to: '/app/akuntansi/kategori-beban', label: 'Kategori beban', icon: 'grid', permission: 'accounting.expense.view', module: 'ACCOUNTING_EXPENSE', feature: 'EXPENSE', group: 'Beban' },
  { to: '/app/akuntansi/kas-bank', label: 'Akun kas & bank', icon: 'building', permission: 'accounting.cash_bank.view', module: 'ACCOUNTING_CASH_BANK', feature: 'CASH_BANK_ACCOUNT', group: 'Kas & bank' },
  { to: '/app/akuntansi/pembayaran-kas', label: 'Pembayaran kas', icon: 'card', permission: 'accounting.cash_transaction.view', module: 'ACCOUNTING_CASH_BANK', feature: 'PAYMENT', group: 'Kas & bank' },
  { to: '/app/akuntansi/penerimaan-kas', label: 'Penerimaan kas', icon: 'box', permission: 'accounting.cash_transaction.view', module: 'ACCOUNTING_CASH_BANK', feature: 'RECEIPT', group: 'Kas & bank' },
  { to: '/app/akuntansi/rekening-koran', label: 'Rekening koran', icon: 'book', permission: 'accounting.bank_reconciliation.view', module: 'ACCOUNTING_CASH_BANK', feature: 'BANK_RECONCILIATION', group: 'Kas & bank' },
  { to: '/app/akuntansi/rekonsiliasi/utang', label: 'Utang vs buku besar', icon: 'scale', permission: 'accounting.reconciliation.ap.view', module: 'ACCOUNTING_AP', feature: 'AP_AGING', group: 'Rekonsiliasi' },
  { to: '/app/akuntansi/rekonsiliasi/kas-bank', label: 'Kas/bank vs buku besar', icon: 'scale', permission: 'accounting.reconciliation.cash_bank.view', module: 'ACCOUNTING_CASH_BANK', feature: 'BANK_RECONCILIATION', group: 'Rekonsiliasi' },
  { to: '/app/akuntansi/profil', label: 'Profil akuntansi', icon: 'cog', permission: 'accounting.profile.view', module: 'ACCOUNTING_CORE', group: 'Konfigurasi akuntansi' },
  { to: '/app/akuntansi/periode', label: 'Tahun fiskal & periode', icon: 'calendar', permission: 'accounting.period.view', module: 'ACCOUNTING_CORE', group: 'Konfigurasi akuntansi' },
  { to: '/app/akuntansi/akun', label: 'Bagan akun', icon: 'grid', permission: 'accounting.coa.view', module: 'ACCOUNTING_CORE', group: 'Konfigurasi akuntansi' },
  { to: '/app/akuntansi/aturan-posting', label: 'Aturan posting', icon: 'tag', permission: 'accounting.posting_rule.view', module: 'ACCOUNTING_CORE', group: 'Konfigurasi akuntansi' },
  { to: '/app/akuntansi/pemetaan-akun', label: 'Pemetaan akun', icon: 'key', permission: 'accounting.account_mapping.view', module: 'ACCOUNTING_CORE', group: 'Konfigurasi akuntansi' },
  { to: '/app/langganan', label: 'Langganan', icon: 'card', permission: 'account.subscription.view', group: 'Akun' },
  { to: '/app/penggunaan', label: 'Penggunaan & batas', icon: 'chart', permission: 'account.subscription.view', group: 'Akun' },
  { to: '/app/audit', label: 'Audit', icon: 'log', permission: 'audit.view', group: 'Akun' },
]

/** Sends the visitor to sign-in, or to the portal their session actually belongs to. */
function RequireScope({ scope, children }: { scope: 'platform' | 'tenant'; children: ReactNode }) {
  const { state } = useAuth()
  const location = useLocation()
  if (state.status === 'loading') return <Loading label="Memuat sesi…" />
  if (state.status === 'anonymous') return <Navigate to="/login" replace state={{ from: location.pathname }} />
  if (state.me.scope !== scope) return <Navigate to={homePath(state.me)} replace />
  return <>{children}</>
}

function Portal({ scope, items }: { scope: 'platform' | 'tenant'; items: NavItem[] }) {
  const { state } = useAuth()
  const tenantId = state.status === 'ready' ? state.me.tenant_id : null
  // Keyed by tenant so a switch discards every cached capability and page state of the previous organisation.
  return (
    <CapabilityProvider key={`${scope}:${tenantId}`} scope={scope} tenantId={tenantId}>
      <Shell scope={scope} items={items} />
    </CapabilityProvider>
  )
}

/** Page-level guard: a direct URL without the permission shows a refusal instead of a failing request. */
function Guard({ permission, module, feature, children }: { permission: string; module?: string; feature?: string; children: ReactNode }) {
  const { can, moduleMode, featureEnabled } = useCapabilities()
  if (module && moduleMode(module) === 'NONE') return <EmptyState title="Modul tidak tersedia">Modul ini tidak termasuk dalam langganan organisasi Anda.</EmptyState>
  if (feature && !featureEnabled(feature)) return <EmptyState title="Fitur tidak tersedia">Fitur ini tidak termasuk dalam langganan organisasi Anda.</EmptyState>
  if (!can(permission)) return <EmptyState title="Akses ditolak">Anda tidak memiliki izin untuk membuka halaman ini.</EmptyState>
  return <>{children}</>
}

function NotFound() {
  return (
    <EmptyState title="Halaman tidak ditemukan" action={<Link to="/">Kembali ke beranda</Link>}>
      Alamat yang Anda buka tidak ada.
    </EmptyState>
  )
}

function Landing() {
  const { state } = useAuth()
  if (state.status === 'loading') return <Loading label="Memuat sesi…" />
  if (state.status === 'anonymous') return <Navigate to="/login" replace />
  return <Navigate to={homePath(state.me)} replace />
}

export default function App() {
  return (
    <Routes>
        <Route path="/" element={<Landing />} />
        <Route path="/login" element={<Login />} />
        <Route path="/sso/callback" element={<SsoCallback />} />
        <Route path="/pilih-akses" element={<ChooseAccess />} />

        <Route path="/platform" element={<RequireScope scope="platform"><Portal scope="platform" items={PLATFORM_NAV} /></RequireScope>}>
          <Route index element={<PlatformDashboard />} />
          <Route path="tenants" element={<Guard permission="platform.tenant.view"><Tenants /></Guard>} />
          <Route path="tenants/:id" element={<Guard permission="platform.tenant.view"><TenantDetail /></Guard>} />
          <Route path="modules" element={<Guard permission="platform.module.view"><Modules /></Guard>} />
          <Route path="bundles" element={<Guard permission="platform.bundle.view"><Bundles /></Guard>} />
          <Route path="operators" element={<Guard permission="platform.user.view"><Operators /></Guard>} />
          <Route path="audit" element={<Guard permission="platform.audit.view"><PlatformAudit /></Guard>} />
          <Route path="*" element={<NotFound />} />
        </Route>

        <Route path="/app" element={<RequireScope scope="tenant"><Portal scope="tenant" items={TENANT_NAV} /></RequireScope>}>
          <Route index element={<TenantDashboard />} />
          <Route path="organisasi" element={<Guard permission="organization.view"><Organization /></Guard>} />
          <Route path="pengguna" element={<Guard permission="access.user.view"><Users /></Guard>} />
          <Route path="peran" element={<Guard permission="access.role.view"><Roles /></Guard>} />
          <Route path="akuntansi">
            <Route index element={<Guard permission="accounting.journal.view" module="ACCOUNTING_CORE"><AccountingHome /></Guard>} />
            <Route path="jurnal" element={<Guard permission="accounting.journal.view" module="ACCOUNTING_CORE"><Journals /></Guard>} />
            <Route path="jurnal/baru" element={<Guard permission="accounting.journal.create" module="ACCOUNTING_CORE"><JournalEditor /></Guard>} />
            <Route path="jurnal/:id" element={<Guard permission="accounting.journal.view" module="ACCOUNTING_CORE"><JournalDetail /></Guard>} />
            <Route path="jurnal/:id/ubah" element={<Guard permission="accounting.journal.update" module="ACCOUNTING_CORE"><JournalEditor /></Guard>} />
            <Route path="buku-besar" element={<Guard permission="accounting.gl.view" module="ACCOUNTING_CORE"><GeneralLedger /></Guard>} />
            <Route path="neraca-saldo" element={<Guard permission="accounting.trial_balance.view" module="ACCOUNTING_CORE"><TrialBalance /></Guard>} />
            <Route path="saldo-awal" element={<Guard permission="accounting.opening_balance.view" module="ACCOUNTING_CORE"><OpeningBalancePage /></Guard>} />
            <Route path="profil" element={<Guard permission="accounting.profile.view" module="ACCOUNTING_CORE"><Profile /></Guard>} />
            <Route path="periode" element={<Guard permission="accounting.period.view" module="ACCOUNTING_CORE"><FiscalCalendar /></Guard>} />
            <Route path="akun" element={<Guard permission="accounting.coa.view" module="ACCOUNTING_CORE"><ChartOfAccounts /></Guard>} />
            <Route path="aturan-posting" element={<Guard permission="accounting.posting_rule.view" module="ACCOUNTING_CORE"><PostingRules /></Guard>} />
            <Route path="pemetaan-akun" element={<Guard permission="accounting.account_mapping.view" module="ACCOUNTING_CORE"><AccountMappings /></Guard>} />
            {/* OA2 */}
            <Route path="vendor" element={<Guard permission="accounting.vendor.view" module="ACCOUNTING_AP" feature="VENDOR"><Vendors /></Guard>} />
            <Route path="faktur-vendor" element={<Guard permission="accounting.ap_invoice.view" module="ACCOUNTING_AP" feature="VENDOR_INVOICE"><ApInvoices /></Guard>} />
            <Route path="faktur-vendor/baru" element={<Guard permission="accounting.ap_invoice.create" module="ACCOUNTING_AP" feature="VENDOR_INVOICE"><ApInvoiceEditor /></Guard>} />
            <Route path="faktur-vendor/:id" element={<Guard permission="accounting.ap_invoice.view" module="ACCOUNTING_AP" feature="VENDOR_INVOICE"><ApInvoiceDetail /></Guard>} />
            <Route path="faktur-vendor/:id/ubah" element={<Guard permission="accounting.ap_invoice.update" module="ACCOUNTING_AP" feature="VENDOR_INVOICE"><ApInvoiceEditor /></Guard>} />
            <Route path="pembayaran-vendor" element={<Guard permission="accounting.ap_payment.view" module="ACCOUNTING_AP" feature="AP_PAYMENT"><VendorPayments /></Guard>} />
            <Route path="pembayaran-vendor/baru" element={<Guard permission="accounting.ap_payment.create" module="ACCOUNTING_AP" feature="AP_PAYMENT"><VendorPaymentEditor /></Guard>} />
            <Route path="pembayaran-vendor/:id" element={<Guard permission="accounting.ap_payment.view" module="ACCOUNTING_AP" feature="AP_PAYMENT"><VendorPaymentDetail /></Guard>} />
            <Route path="pembayaran-vendor/:id/ubah" element={<Guard permission="accounting.ap_payment.create" module="ACCOUNTING_AP" feature="AP_PAYMENT"><VendorPaymentEditor /></Guard>} />
            <Route path="umur-utang" element={<Guard permission="accounting.ap_aging.view" module="ACCOUNTING_AP" feature="AP_AGING"><ApAging /></Guard>} />
            <Route path="rekonsiliasi/utang" element={<Guard permission="accounting.reconciliation.ap.view" module="ACCOUNTING_AP" feature="AP_AGING"><ApReconciliation /></Guard>} />
            <Route path="beban" element={<Guard permission="accounting.expense.view" module="ACCOUNTING_EXPENSE" feature="EXPENSE"><Expenses /></Guard>} />
            <Route path="beban/baru" element={<Guard permission="accounting.expense.create" module="ACCOUNTING_EXPENSE" feature="EXPENSE"><ExpenseEditor /></Guard>} />
            <Route path="beban/:id" element={<Guard permission="accounting.expense.view" module="ACCOUNTING_EXPENSE" feature="EXPENSE"><ExpenseDetail /></Guard>} />
            <Route path="beban/:id/ubah" element={<Guard permission="accounting.expense.update" module="ACCOUNTING_EXPENSE" feature="EXPENSE"><ExpenseEditor /></Guard>} />
            <Route path="kategori-beban" element={<Guard permission="accounting.expense.view" module="ACCOUNTING_EXPENSE" feature="EXPENSE"><ExpenseCategories /></Guard>} />
            <Route path="kas-bank" element={<Guard permission="accounting.cash_bank.view" module="ACCOUNTING_CASH_BANK" feature="CASH_BANK_ACCOUNT"><CashBankAccounts /></Guard>} />
            <Route path="kas-bank/:id" element={<Guard permission="accounting.cash_bank.view" module="ACCOUNTING_CASH_BANK" feature="CASH_BANK_ACCOUNT"><CashBankAccountDetail /></Guard>} />
            <Route path="pembayaran-kas" element={<Guard permission="accounting.cash_transaction.view" module="ACCOUNTING_CASH_BANK" feature="PAYMENT"><CashTransactions kind="PAYMENT" /></Guard>} />
            <Route path="pembayaran-kas/baru" element={<Guard permission="accounting.cash_transaction.create" module="ACCOUNTING_CASH_BANK" feature="PAYMENT"><CashTransactionEditor kind="PAYMENT" /></Guard>} />
            <Route path="pembayaran-kas/:id" element={<Guard permission="accounting.cash_transaction.view" module="ACCOUNTING_CASH_BANK" feature="PAYMENT"><CashTransactionDetail kind="PAYMENT" /></Guard>} />
            <Route path="pembayaran-kas/:id/ubah" element={<Guard permission="accounting.cash_transaction.create" module="ACCOUNTING_CASH_BANK" feature="PAYMENT"><CashTransactionEditor kind="PAYMENT" /></Guard>} />
            <Route path="penerimaan-kas" element={<Guard permission="accounting.cash_transaction.view" module="ACCOUNTING_CASH_BANK" feature="RECEIPT"><CashTransactions kind="RECEIPT" /></Guard>} />
            <Route path="penerimaan-kas/baru" element={<Guard permission="accounting.cash_transaction.create" module="ACCOUNTING_CASH_BANK" feature="RECEIPT"><CashTransactionEditor kind="RECEIPT" /></Guard>} />
            <Route path="penerimaan-kas/:id" element={<Guard permission="accounting.cash_transaction.view" module="ACCOUNTING_CASH_BANK" feature="RECEIPT"><CashTransactionDetail kind="RECEIPT" /></Guard>} />
            <Route path="penerimaan-kas/:id/ubah" element={<Guard permission="accounting.cash_transaction.create" module="ACCOUNTING_CASH_BANK" feature="RECEIPT"><CashTransactionEditor kind="RECEIPT" /></Guard>} />
            <Route path="rekening-koran" element={<Guard permission="accounting.bank_reconciliation.view" module="ACCOUNTING_CASH_BANK" feature="BANK_RECONCILIATION"><BankStatements /></Guard>} />
            <Route path="rekening-koran/:id" element={<Guard permission="accounting.bank_reconciliation.view" module="ACCOUNTING_CASH_BANK" feature="BANK_RECONCILIATION"><BankStatementDetail /></Guard>} />
            <Route path="rekonsiliasi/kas-bank" element={<Guard permission="accounting.reconciliation.cash_bank.view" module="ACCOUNTING_CASH_BANK" feature="BANK_RECONCILIATION"><CashBankReconciliation /></Guard>} />
          </Route>
          <Route path="langganan" element={<Guard permission="account.subscription.view"><Subscription /></Guard>} />
          <Route path="penggunaan" element={<Guard permission="account.subscription.view"><Usage /></Guard>} />
          <Route path="audit" element={<Guard permission="audit.view"><TenantAudit /></Guard>} />
          <Route path="*" element={<NotFound />} />
        </Route>

        <Route path="*" element={<div className="auth"><Banner tone="info">Halaman tidak ditemukan. <Link to="/">Kembali ke beranda</Link></Banner></div>} />
    </Routes>
  )
}
