import { useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { Badge, Banner, Card, EmptyState, Loading, PageHeader, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { formatDate, todayIn } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { API, MODULES, useCashBankAccounts, useModuleAccess } from '../../lib/operational'
import { cashKindLabels } from '../../lib/operationalLabels'
import type { CashBankReport, ReportAccount } from './bank/types'
import { ErrorNotice, ExportButton, Filters, Money, ReadOnlyNotice } from './shared'

export default function CashBankReconciliation() {
  const { tenant, can } = useCapabilities()
  const { readOnly } = useModuleAccess(MODULES.cashBank)
  const [asOf, setAsOf] = useState(tenant?.business_date ?? todayIn())
  const [accountId, setAccountId] = useState('')

  const report = useResource(
    async () => (await api.get<CashBankReport>(`${API}/reconciliation/cash-bank`, { params: { ...(asOf && { as_of: asOf }), ...(accountId && { cash_bank_account_id: accountId }) } })).data,
    [asOf, accountId],
  )

  const r = report.data
  return (
    <>
      <PageHeader
        title="Kas/bank vs buku besar"
        description="Membandingkan dokumen kas dan bank dengan baris buku besar akunnya, dan menampilkan saldo buku berdampingan dengan saldo rekening koran. Laporan ini hanya membaca: tidak ada jurnal yang dibuat atau diubah, dan selisih tidak disesuaikan otomatis."
        actions={<ExportButton path={`${API}/reconciliation/cash-bank/export`} params={{ as_of: asOf, cash_bank_account_id: accountId }} filename={`rekonsiliasi-kas-bank_${asOf || 'hari-ini'}.csv`} />}
      />
      <ReadOnlyNotice show={readOnly} />

      <Card flush>
        <div className="card-body">
          <Filters>
            <label className="inline-field">Per tanggal <input className="input" type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} /></label>
            {can('accounting.cash_bank.view') && <AccountFilter value={accountId} onChange={setAccountId} />}
          </Filters>
        </div>
      </Card>

      {report.loading && !r ? (
        <Loading />
      ) : report.error || !r ? (
        <ErrorNotice error={report.error} onRetry={report.reload} />
      ) : (
        <>
          {!r.complete && (
            <Banner tone="warn">Cakupan sebagian: laporan ini hanya memuat akun kas/bank dalam cakupan data Anda (cabang atau unit bisnis tertentu). Total saldo buku di bawah tidak mewakili seluruh organisasi.</Banner>
          )}
          {r.accounts.length === 0 ? (
            <EmptyState title="Tidak ada akun kas/bank">Tidak ada akun kas atau bank dalam cakupan Anda untuk filter ini.</EmptyState>
          ) : (
            <>
              <Banner tone={r.status === 'MATCHED' ? 'ok' : 'bad'}>
                {r.status === 'MATCHED'
                  ? `Dokumen sesuai dengan buku besar pada semua akun per ${formatDate(r.as_of)}: mutasi dokumen sama dengan mutasi buku besar yang berasal dari dokumen tersebut.`
                  : `${r.mismatched_accounts} akun kas/bank memiliki selisih antara dokumen dan buku besar per ${formatDate(r.as_of)}. Selisih ditampilkan apa adanya dan tidak disesuaikan otomatis; periksa akun bertanda "Selisih" di bawah.`}
              </Banner>
              <p className="muted">
                Total saldo buku {r.complete ? '' : '(cakupan Anda) '}per {formatDate(r.as_of)}: <Money value={r.book_balance} strong />
              </p>
              {r.accounts.map((account) => <AccountCard key={account.cash_bank_account_id} account={account} />)}
            </>
          )}
        </>
      )}
    </>
  )
}

/** Account filter; its own component so the accounts are requested only for users who may read them. */
function AccountFilter({ value, onChange }: { value: string; onChange: (id: string) => void }) {
  const { accounts } = useCashBankAccounts()
  return (
    <select className="select" aria-label="Akun kas/bank" value={value} onChange={(e) => onChange(e.target.value)}>
      <option value="">Semua akun kas & bank</option>
      {accounts.map((a) => <option key={a.id} value={a.id}>{a.code} · {a.name}</option>)}
    </select>
  )
}

function Fact({ label, hint, wide, children }: { label: string; hint?: ReactNode; wide?: boolean; children: ReactNode }) {
  return (
    <div className={wide ? 'wide' : undefined}>
      <dt>{label}</dt>
      <dd>{children}</dd>
      {hint && <div className="muted" style={{ fontSize: '0.8rem', fontWeight: 400 }}>{hint}</div>}
    </div>
  )
}

function AccountCard({ account: a }: { account: ReportAccount }) {
  const { can } = useCapabilities()
  const d = a.documents
  return (
    <Card
      title={`${a.code} · ${a.name}`}
      actions={
        <span className="chips">
          <Badge tone="info">{cashKindLabels[a.kind] ?? a.kind}</Badge>
          {a.status === 'INACTIVE' && <StatusBadge status="INACTIVE" />}
          <StatusBadge status={d.status} />
        </span>
      }
    >
      <div className="grid grid-2">
        <section className="stack" aria-label={`Dokumen vs buku besar ${a.code}`}>
          <h3>Dokumen vs buku besar</h3>
          <dl className="facts">
            <Fact label="Akun buku besar">{a.gl_account.code ? `${a.gl_account.code} · ${a.gl_account.name ?? ''}` : '—'}</Fact>
            <Fact label="Saldo buku" hint="Dari baris buku besar yang sudah diposting."><Money value={a.book_balance} strong /></Fact>
            <Fact label="Penerimaan kas"><Money value={d.receipts} /></Fact>
            <Fact label="Pembayaran kas"><Money value={d.cash_payments} /></Fact>
            <Fact label="Pembayaran vendor"><Money value={d.vendor_payments} /></Fact>
            <Fact label="Beban dibayar langsung"><Money value={d.paid_expenses} /></Fact>
            <Fact label="Mutasi bersih dokumen"><Money value={d.net} strong /></Fact>
            <Fact label="Mutasi buku besar dari dokumen"><Money value={d.ledger_net} strong /></Fact>
            <Fact label="Selisih dokumen" hint="Harus nol: jurnal sebuah dokumen harus sesuai dengan dokumennya."><Money value={d.difference} strong /> <StatusBadge status={d.status} /></Fact>
            <Fact label="Aktivitas lain di buku besar" wide hint="Saldo awal, jurnal manual, dan pencatatan lain selain dokumen kas/bank. Dijelaskan di sini, bukan kesalahan.">
              <Money value={a.other_activity} />
            </Fact>
          </dl>
        </section>

        <section className="stack" aria-label={`Rekening koran terakhir ${a.code}`}>
          <h3>Rekening koran terakhir</h3>
          {a.statement ? (
            <dl className="facts">
              <Fact label="Referensi">
                {can('accounting.bank_reconciliation.view') ? <Link to={`/app/akuntansi/rekening-koran/${a.statement.id}`} className="mono">{a.statement.reference}</Link> : <span className="mono">{a.statement.reference}</span>}
              </Fact>
              <Fact label="Tanggal">{formatDate(a.statement.statement_date)}</Fact>
              <Fact label="Saldo rekening koran" hint="Bukti dari bank, bukan kebenaran buku besar."><Money value={a.statement.statement_balance} strong /></Fact>
              <Fact label="Buku dikurangi rekening koran"><Money value={a.statement.book_minus_statement} /></Fact>
              <Fact label="Selisih tidak terjelaskan" hint={a.statement.unexplained_difference === null ? 'Belum ada: rekonsiliasi belum diselesaikan.' : 'Dibekukan saat rekonsiliasi diselesaikan.'}>
                {a.statement.unexplained_difference === null ? '—' : <Money value={a.statement.unexplained_difference} />}
              </Fact>
              <Fact label="Status rekonsiliasi"><StatusBadge status={a.statement.reconciliation} /></Fact>
            </dl>
          ) : (
            <p className="muted">
              {a.kind === 'CASH'
                ? 'Akun kas tidak memiliki rekening koran.'
                : <>Belum ada rekening koran pada atau sebelum tanggal ini.{can('accounting.bank_reconciliation.view') && <> <Link to="/app/akuntansi/rekening-koran">Buka rekening koran</Link></>}</>}
            </p>
          )}
        </section>
      </div>
    </Card>
  )
}
