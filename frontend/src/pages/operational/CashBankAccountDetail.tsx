import { Link, useParams } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { Badge, Card, EmptyState, Loading, PageHeader, Pagination, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { formatDate } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { API } from '../../lib/operational'
import { cashKindLabels } from '../../lib/operationalLabels'
import { movementSourceLabels, type CashAccount, type Movement } from './cash/types'
import { localized } from './expense/errors'
import { usePagedList } from './expense/usePagedList'
import { ErrorNotice, ExportButton, Filters, Money, Side } from './shared'

const INITIAL = { q: '', from: '', to: '', direction: '', matched: '' }

export default function CashBankAccountDetail() {
  const { id } = useParams()
  const account = useResource(async () => (await api.get<CashAccount>(`${API}/cash-bank-accounts/${id}`)).data, [id])

  if (account.loading && !account.data) return <Loading />
  if (account.error || !account.data) return <ErrorNotice error={localized(account.error)} onRetry={account.reload} />
  const a = account.data

  return (
    <>
      <PageHeader
        title={`${a.code} · ${a.name}`}
        description={<>{cashKindLabels[a.kind] ?? a.kind} · <StatusBadge status={a.status} /></>}
      />

      <Card title="Akun">
        <dl className="facts">
          <div><dt>Saldo buku</dt><dd><Money value={a.book_balance} strong /></dd></div>
          <div><dt>Akun buku besar</dt><dd>{a.gl_account ? `${a.gl_account.code} · ${a.gl_account.name}` : '—'}</dd></div>
          <div><dt>Mata uang</dt><dd>{a.currency}</dd></div>
          {a.kind === 'BANK' && (
            <>
              <div><dt>Bank</dt><dd>{a.bank_name ?? '—'}</dd></div>
              <div><dt>Pemilik rekening</dt><dd>{a.account_holder ?? '—'}</dd></div>
              <div><dt>Nomor rekening</dt><dd className="mono">{a.account_number_masked ?? '—'}</dd></div>
            </>
          )}
          {a.branch && <div><dt>Cabang</dt><dd>{a.branch.code} · {a.branch.name}</dd></div>}
          {a.business_unit && <div><dt>Unit bisnis</dt><dd>{a.business_unit.code} · {a.business_unit.name}</dd></div>}
          {a.notes && <div className="wide"><dt>Catatan</dt><dd>{a.notes}</dd></div>}
        </dl>
        <p className="muted preview-note">Saldo buku diturunkan dari jurnal yang sudah diposting pada akun buku besar di atas. Ini bukan saldo yang dapat diedit, dan bukan saldo menurut rekening koran.</p>
      </Card>

      <Movements account={a} />
    </>
  )
}

function Movements({ account }: { account: CashAccount }) {
  const { can } = useCapabilities()
  const base = `${API}/cash-bank-accounts/${account.id}/transactions`
  const bank = account.kind === 'BANK'
  const { filter, change, setPage, list, exportParams } = usePagedList<Movement, typeof INITIAL>(base, INITIAL, (f, page) => {
    const params: Record<string, string | number> = { page }
    for (const key of ['q', 'from', 'to', 'direction'] as const) if (f[key]) params[key] = f[key]
    if (f.matched) params.matched = f.matched === 'yes' ? 1 : 0 // sent explicitly in both directions: "belum dicocokkan" is matched=0 (the API's boolean rule takes 1/0, not "true")
    return params
  })

  return (
    <Card title="Mutasi" flush>
      <div className="card-body">
        <Filters>
          <input className="input" type="search" placeholder="Cari keterangan, referensi, nomor jurnal" aria-label="Cari mutasi" value={filter.q} onChange={(e) => change('q', e.target.value)} />
          <label className="inline-field">Dari <input className="input" type="date" aria-label="Mutasi dari tanggal" value={filter.from} onChange={(e) => change('from', e.target.value)} /></label>
          <label className="inline-field">Sampai <input className="input" type="date" aria-label="Mutasi sampai tanggal" value={filter.to} onChange={(e) => change('to', e.target.value)} /></label>
          <select className="select" aria-label="Arah mutasi" value={filter.direction} onChange={(e) => change('direction', e.target.value)}>
            <option value="">Masuk dan keluar</option>
            <option value="IN">Masuk</option>
            <option value="OUT">Keluar</option>
          </select>
          {bank && (
            <select className="select" aria-label="Status pencocokan" value={filter.matched} onChange={(e) => change('matched', e.target.value)}>
              <option value="">Semua pencocokan</option>
              <option value="yes">Sudah dicocokkan</option>
              <option value="no">Belum dicocokkan</option>
            </select>
          )}
          <span className="spacer" />
          {bank && can('accounting.bank_reconciliation.view') && <Link to="/app/akuntansi/rekening-koran" className="btn">Rekening koran</Link>}
          <ExportButton path={`${base}/export`} params={exportParams} filename={`mutasi-${account.code}.csv`} />
        </Filters>
      </div>

      {list.loading && !list.data ? (
        <Loading />
      ) : list.error || !list.data ? (
        <ErrorNotice error={list.error} onRetry={list.reload} />
      ) : list.data.data.length === 0 ? (
        <EmptyState title="Belum ada mutasi">Hanya jurnal yang sudah diposting pada akun buku besar ini yang tampil. Ubah filter bila perlu.</EmptyState>
      ) : (
        <>
          <DataTable
            caption="Mutasi akun kas/bank"
            scroll
            rows={list.data.data}
            rowKey={(m) => m.journal_line_id}
            columns={[
              { header: 'Tanggal posting', primary: true, cell: (m) => formatDate(m.posting_date) },
              { header: 'Jurnal', cell: (m) => <Link to={`/app/akuntansi/jurnal/${m.journal_entry_id}`} className="mono">{m.journal_number ?? 'Jurnal'}</Link> },
              { header: 'Dokumen', cell: (m) => (m.document_number ? <>{m.document_number}<div className="muted">{movementSourceLabels[m.source_type ?? ''] ?? m.source_type}</div></> : <span className="muted">{(m.source_type && movementSourceLabels[m.source_type]) || '—'}</span>) },
              { header: 'Keterangan', cell: (m) => <>{m.description}{m.reference && <div className="muted">Ref {m.reference}</div>}</> },
              { header: 'Masuk', align: 'right', cell: (m) => <Side value={m.debit} /> },
              { header: 'Keluar', align: 'right', cell: (m) => <Side value={m.credit} /> },
              { header: 'Saldo berjalan', align: 'right', cell: (m) => <Money value={m.running_balance} /> },
              ...(bank ? [{ header: 'Pencocokan', cell: (m: Movement) => (m.matched_statement_id
                ? <><Badge tone="ok">Cocok</Badge> <Link to={`/app/akuntansi/rekening-koran/${m.matched_statement_id}`}>{m.matched_statement}</Link></>
                : <Badge tone="warn">Belum dicocokkan</Badge>) }] : []),
            ]}
          />
          <Pagination page={list.data.current_page} lastPage={list.data.last_page} total={list.data.total} onPage={setPage} />
        </>
      )}
    </Card>
  )
}
