import type { ReactNode } from 'react'
import { Badge, Banner, Card, StatusBadge } from '../../../components/ui'
import { formatDate, formatDateTime, formatNumber } from '../../../lib/format'
import { Money } from '../shared'
import type { BankStatement, StatementSummary } from './types'

function Fact({ label, hint, wide, children }: { label: string; hint?: ReactNode; wide?: boolean; children: ReactNode }) {
  return (
    <div className={wide ? 'wide' : undefined}>
      <dt>{label}</dt>
      <dd>{children}</dd>
      {hint && <div className="muted" style={{ fontSize: '0.8rem', fontWeight: 400 }}>{hint}</div>}
    </div>
  )
}

/**
 * The reconciliation figures exactly as the API computes them. The UI never recomputes a balance, a net or a difference: the
 * equation below is only explained here so that the numbers can be read.
 */
export function SummaryPanel({ statement, summary }: { statement: BankStatement; summary: StatementSummary }) {
  const completed = statement.status === 'COMPLETED'
  const s = summary
  return (
    <Card title="Ringkasan rekonsiliasi" actions={<StatusBadge status={s.status} />}>
      <div className="stack">
        {completed ? (
          <Banner tone="ok">
            Rekonsiliasi selesai {statement.completed_at ? formatDateTime(statement.completed_at) : ''}{statement.completer ? ` oleh ${statement.completer.name}` : ''}. Angka di bawah adalah bukti yang dibekukan saat penyelesaian; rekening koran dan barisnya tidak dapat diubah lagi.
          </Banner>
        ) : (
          <Banner tone="info">Angka berikut dihitung langsung dari buku besar dan baris yang sudah Anda cocokkan; angka ini berubah setiap kali Anda mencocokkan atau menandai baris.</Banner>
        )}

        <p className="muted">
          Persamaan rekonsiliasi: <strong>(saldo rekening koran + mutasi buku yang belum dicocokkan) − (saldo buku + pengecualian) = selisih tidak terjelaskan</strong>.
          Selisih nol berarti setiap perbedaan antara bank dan buku sudah dijelaskan oleh baris yang belum dicocokkan atau pengecualian. Bila ada selisih, periksa saldo yang dimasukkan dan baris yang belum dicocokkan; sistem tidak membuat jurnal penyesuaian.
        </p>

        <dl className="facts">
          <Fact label="Saldo rekening koran" hint="Saldo akhir menurut bank yang Anda masukkan."><Money value={s.statement_balance} strong /></Fact>
          <Fact label="Saldo buku" hint={`Saldo akun buku besar per ${formatDate(statement.statement_date)}${completed ? ' saat diselesaikan' : ''}.`}><Money value={s.book_balance} strong /></Fact>
          <Fact label="Buku dikurangi rekening koran" hint="Selisih mentah antara dua saldo di atas."><Money value={s.book_minus_statement} /></Fact>
          <Fact
            label="Mutasi buku belum dicocokkan"
            hint={
              <>
                {s.unmatched_book_lines !== null && <>{formatNumber(s.unmatched_book_lines)} baris buku tanpa pasangan di rekening koran. </>}
                Contoh: cek yang belum cair.
              </>
            }
          >
            <Money value={s.unmatched_book_net} />
          </Fact>
          <Fact label="Pengecualian" hint="Baris rekening koran yang belum ada di buku, misalnya biaya bank."><Money value={s.exception_statement_net} /></Fact>
          <Fact label="Selisih tidak terjelaskan" hint={s.status === 'RECONCILED' ? 'Terekonsiliasi: semua perbedaan sudah dijelaskan.' : 'Masih ada perbedaan yang belum dijelaskan.'}>
            <Money value={s.unexplained_difference} strong />
          </Fact>
          <Fact label="Status rekonsiliasi"><StatusBadge status={s.status} /></Fact>
          <Fact label="Total baris rekening koran" hint="Jumlah bertanda semua baris yang dimasukkan."><Money value={s.items_net} /></Fact>
          <Fact label="Konsistensi rekening koran" wide hint={consistencyHint(s.statement_consistent)}>
            {s.statement_consistent === null ? <Badge>Tidak dapat diuji</Badge> : s.statement_consistent ? <Badge tone="ok">Konsisten</Badge> : <Badge tone="bad">Tidak konsisten</Badge>}
          </Fact>
          <Fact label="Baris" wide>
            {formatNumber(s.matched_items)} cocok · {formatNumber(s.exception_items)} pengecualian · {formatNumber(s.unmatched_items)} belum dicocokkan
          </Fact>
        </dl>
      </div>
    </Card>
  )
}

function consistencyHint(consistent: boolean | null): string {
  if (consistent === null) return 'Saldo awal tidak diisi, jadi saldo awal + total baris = saldo akhir tidak dapat diuji.'
  return consistent ? 'Saldo awal + total baris sama dengan saldo akhir.' : 'Saldo awal + total baris tidak sama dengan saldo akhir: periksa baris yang hilang atau salah ketik.'
}
