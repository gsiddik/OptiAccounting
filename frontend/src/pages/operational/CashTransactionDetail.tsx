import { Link, useParams } from 'react-router-dom'
import { Banner, Card, Loading, PageHeader, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { formatDate, formatDateTime } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { API, MODULES, useModuleAccess } from '../../lib/operational'
import { KIND, type CashKind, type CashTransaction } from './cash/types'
import { localized } from './expense/errors'
import { ErrorNotice, Money, ReadOnlyNotice, Timeline } from './shared'
import { useDocumentActions } from './workflow'

export default function CashTransactionDetail({ kind }: { kind: CashKind }) {
  const { id } = useParams()
  const k = KIND[kind]
  const base = `${API}/${k.path}/${id}`
  const transaction = useResource(async () => (await api.get<CashTransaction>(base)).data, [base])
  const { readOnly } = useModuleAccess(MODULES.cashBank)
  const t = transaction.data
  // A cash payment or receipt has no approval step: a draft is posted directly (when the user may post it) or cancelled.
  const actions = useDocumentActions({
    base,
    status: t?.status ?? '',
    sod: t?.sod,
    noun: k.noun,
    approvalFlow: false,
    perms: { update: 'accounting.cash_transaction.create', post: 'accounting.cash_transaction.post', reverse: 'accounting.cash_transaction.reverse' },
    modules: [MODULES.cashBank],
    editPath: `${k.route}/${id}/ubah`,
    onChanged: transaction.reload,
  })

  if (transaction.loading && !t) return <Loading />
  if (transaction.error || !t) return <ErrorNotice error={localized(transaction.error)} onRetry={transaction.reload} />

  return (
    <>
      <PageHeader
        title={t.document_number ?? (t.status === 'DRAFT' ? `Draf ${k.title.toLowerCase()}` : `${k.title} belum bernomor`)}
        description={<>{k.title} · <StatusBadge status={t.status} /></>}
        actions={actions.buttons}
      />
      <ReadOnlyNotice show={readOnly} />
      {actions.notes.map((n) => <Banner key={n} tone="info">{n}</Banner>)}
      {actions.error != null && <ErrorNotice error={localized(actions.error)} />}
      {t.status === 'CANCELLED' && t.cancel_reason && <Banner tone="info">Dibatalkan: {t.cancel_reason}</Banner>}

      <Card title="Rincian transaksi">
        <dl className="facts">
          <div><dt>Jumlah</dt><dd><Money value={t.amount} strong /> {t.currency}</dd></div>
          <div><dt>Akun kas/bank</dt><dd>{t.cash_bank_account ? <Link to={`/app/akuntansi/kas-bank/${t.cash_bank_account.id}`}>{t.cash_bank_account.code} · {t.cash_bank_account.name}</Link> : '—'}{t.cash_bank_account?.account_number_masked && <span className="muted mono"> {t.cash_bank_account.account_number_masked}</span>}</dd></div>
          <div><dt>{k.counterShort}</dt><dd>{t.counter_account ? `${t.counter_account.code} · ${t.counter_account.name}` : '—'}</dd></div>
          <div><dt>Tanggal transaksi</dt><dd>{formatDate(t.transaction_date)}</dd></div>
          <div><dt>Tanggal posting</dt><dd>{formatDate(t.posting_date)}</dd></div>
          <div><dt>{kind === 'PAYMENT' ? 'Dibayarkan kepada' : 'Diterima dari'}</dt><dd>{t.counterparty_name ?? '—'}</dd></div>
          <div><dt>Referensi</dt><dd>{t.reference ?? '—'}</dd></div>
          <div><dt>Dibuat oleh</dt><dd>{t.creator?.name ?? '—'}</dd></div>
          {t.posted_at && <div><dt>Diposting pada</dt><dd>{formatDateTime(t.posted_at)}</dd></div>}
          {t.branch && <div><dt>Cabang</dt><dd>{t.branch.code} · {t.branch.name}</dd></div>}
          {t.business_unit && <div><dt>Unit bisnis</dt><dd>{t.business_unit.code} · {t.business_unit.name}</dd></div>}
          {t.cost_center && <div><dt>Pusat biaya</dt><dd>{t.cost_center.code} · {t.cost_center.name}</dd></div>}
          {t.journal_entry_id && <div><dt>Jurnal</dt><dd><Link to={`/app/akuntansi/jurnal/${t.journal_entry_id}`}>Lihat jurnal</Link></dd></div>}
          {t.reversal_journal_id && <div><dt>Jurnal pembalik</dt><dd><Link to={`/app/akuntansi/jurnal/${t.reversal_journal_id}`}>Lihat jurnal pembalik</Link></dd></div>}
          <div className="wide"><dt>Tujuan</dt><dd>{t.purpose}</dd></div>
          <div className="wide"><dt>Deskripsi</dt><dd>{t.description}</dd></div>
          {t.reversal_reason && <div className="wide"><dt>Alasan pembalikan{t.reversal_posting_date ? ` (${formatDate(t.reversal_posting_date)})` : ''}</dt><dd>{t.reversal_reason}</dd></div>}
        </dl>
        <p className="muted preview-note">{k.posting}</p>
      </Card>

      <Card title="Riwayat">
        <Timeline transitions={t.transitions} />
      </Card>
      {actions.dialogs}
    </>
  )
}
