import { Link } from 'react-router-dom'
import { Banner, Button, Card, ErrorNotice } from '../../components/ui'
import { formatDate, formatDateTime, formatNumber } from '../../lib/format'
import { Facts } from './components'
import { PATHS, settingsOf, useSettingOptions } from './data'
import { cutoverPolicyLabels, gapPolicyLabels, integrationError, sourceLabel } from './labels'
import type { Connection, ConnectionSummary } from './types'

/** Event counts by processing state; a non-zero count opens the inbox narrowed to that state. */
export function SummaryCard({ connection, summary, error, onRetry }: { connection: Connection; summary: ConnectionSummary | null; error: unknown; onRetry: () => void }) {
  if (error) return <Card title="Kejadian diterima"><ErrorNotice error={integrationError(error)} onRetry={onRetry} /></Card>
  if (!summary) return <Card title="Kejadian diterima"><p className="muted">Memuat ringkasan…</p></Card>
  const count = (status: string) => summary.counts[status] ?? 0
  const link = (n: number, status?: string) => (n > 0 ? <Link to={PATHS.connectionEvents(connection.id, status)}>{formatNumber(n)}</Link> : formatNumber(n))
  return (
    <Card title="Kejadian diterima">
      <Facts rows={[
        ['Diterima', link(summary.received)],
        ['Diposting', link(summary.posted, 'POSTED')],
        ['Menunggu diproses', link(count('RECEIVED'), 'RECEIVED')],
        ['Perlu ditinjau', link(count('NEEDS_REVIEW'), 'NEEDS_REVIEW')],
        ['Gagal, dicoba lagi', link(count('FAILED'), 'FAILED')],
        ['Gagal permanen (dead letter)', link(count('DEAD_LETTER'), 'DEAD_LETTER')],
        ['Ditolak', link(count('REJECTED'), 'REJECTED')],
        ['Terkirim ganda', formatNumber(summary.duplicates)],
        ['Konflik isi', formatNumber(summary.conflicts)],
      ]} />
      <p className="muted preview-note">Perbandingan dengan total di sistem sumber belum tersedia, jadi kejadian yang tidak pernah terkirim belum dapat terdeteksi di sini.</p>
    </Card>
  )
}

export function ConfigCard({ connection, onEdit }: { connection: Connection; onEdit?: () => void }) {
  return (
    <Card title="Konfigurasi" actions={onEdit && <Button onClick={onEdit}>Ubah</Button>}>
      <Facts rows={[
        ['Sistem sumber', sourceLabel(connection.source_system)],
        ['Referensi tenant sumber', connection.source_tenant_reference ?? 'Belum diisi'],
        ['URL sistem sumber', connection.base_url ?? 'Belum diisi'],
        ['Kredensial', connection.has_credential ? 'Tersimpan (tidak ditampilkan)' : 'Belum diisi'],
        ['Tanggal cutover', connection.cutover_date ? formatDate(connection.cutover_date) : 'Belum diisi'],
        ['Kebijakan cutover', cutoverPolicyLabels[connection.cutover_policy] ?? connection.cutover_policy],
        ['Kebijakan jeda', gapPolicyLabels[connection.gap_policy] ?? connection.gap_policy],
        ['Interval tarik', `${connection.pull_interval_minutes} menit`],
        ['Tarikan terakhir', connection.last_pulled_at ? formatDateTime(connection.last_pulled_at) : 'Belum pernah'],
        ['Kursor terakhir', connection.last_cursor && <span key="cursor" className="mono">{connection.last_cursor}</span>],
        ['Gagal berturut-turut', String(connection.consecutive_failures)],
      ]} />
    </Card>
  )
}

/** OptiFleet connector settings (required before the connection can be activated). Names come from the tenant's own lists; an id that cannot be resolved is not guessed. */
export function SettingsCard({ connection, onEdit }: { connection: Connection; onEdit?: () => void }) {
  const options = useSettingOptions()
  const current = settingsOf(connection)
  const cash = current.payables_cash_bank_account_id
  const category = current.expense_category_id
  const cashName = options.accounts.find((a) => a.id === cash)
  const categoryName = options.categories.find((c) => c.id === category)
  const missing = !cash || !category
  return (
    <Card title="Pengaturan OptiFleet" actions={onEdit && <Button onClick={onEdit}>Ubah pengaturan</Button>}>
      {missing && <Banner tone="warn">Akun kas/bank pembayaran dan kategori beban wajib diisi sebelum koneksi OptiFleet dapat diaktifkan.</Banner>}
      <Facts rows={[
        ['Akun kas/bank pembayaran utang', cash ? (cashName ? `${cashName.code} · ${cashName.name}` : 'Sudah dipilih') : 'Belum dipilih'],
        ['Kategori beban faktur bengkel', category ? (categoryName ? `${categoryName.code} · ${categoryName.name}` : 'Sudah dipilih') : 'Belum dipilih'],
      ]} />
    </Card>
  )
}
