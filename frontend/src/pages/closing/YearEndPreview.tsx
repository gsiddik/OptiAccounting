import { DataTable } from '../../components/DataTable'
import { Badge, Banner, Card, EmptyState } from '../../components/ui'
import { Money } from '../operational/shared'
import { Bullets, Facts, Tagged } from './components'
import { accountTypeShort, blockerTitles, detailLabels, resultText, sideLabels } from './labels'
import type { Blocker, YearEndPreview } from './types'

/** The details of a blocker as readable lines: arrays joined, empty values left out. */
function detailLines(details: Record<string, unknown> | undefined): string[] {
  return Object.entries(details ?? {}).flatMap(([key, value]) => {
    const text = Array.isArray(value) ? value.map(String).join(', ') : typeof value === 'string' || typeof value === 'number' ? String(value) : ''
    return text === '' ? [] : [`${detailLabels[key] ?? key}: ${text}`]
  })
}

function Blockers({ blockers }: { blockers: Blocker[] }) {
  return (
    <Banner tone="bad">
      <strong>Belum dapat dijalankan.</strong> Server melaporkan {blockers.length} penghalang; semuanya harus selesai sebelum tutup tahun diterima.
      <Bullets>
        {blockers.map((b) => (
          <li key={b.code}>
            <strong>{blockerTitles[b.code] ?? b.message}</strong> <span className="muted">({b.code})</span>
            {detailLines(b.details).map((line) => <div key={line} className="muted">{line}</div>)}
          </li>
        ))}
      </Bullets>
    </Banner>
  )
}

/** What a run would do today, exactly as the server computed it from posted journal lines: blockers, result, the closing journal check, the groups and the accounts it moves. */
export function PreviewPanels({ preview }: { preview: YearEndPreview }) {
  const { totals, journal } = preview
  return (
    <>
      {preview.can_run ? <Banner tone="ok"><strong>Dapat dijalankan.</strong> Tidak ada penghalang pada saat dihitung. Server menghitung ulang di dalam transaksi saat dijalankan.</Banner> : <Blockers blockers={preview.blockers} />}

      <Card title="Hasil yang akan ditutup">
        <div className="stack">
          <Facts rows={[
            ['Total pendapatan', <Money key="r" value={totals.revenue} />],
            ['Total beban', <Money key="e" value={totals.expense} />],
            ['Laba', <Money key="p" value={totals.profit} />],
            ['Rugi', <Money key="l" value={totals.loss} />],
            ['Hasil bersih', <Money key="n" value={totals.net_result} strong />],
            ['Hasil', <Tagged key="t" text={resultText(totals.result)} />],
          ]} />
          {journal ? (
            <Facts rows={[
              ['Jurnal penutupan', journal.balanced ? <Badge key="b" tone="ok">Seimbang</Badge> : <Badge key="b" tone="bad">Tidak seimbang</Badge>],
              ['Total debit', journal.total_debit && <Money key="d" value={journal.total_debit} />],
              ['Total kredit', journal.total_credit && <Money key="c" value={journal.total_credit} />],
              ['Jumlah baris jurnal', journal.line_count],
              ['Kesalahan aturan', journal.error],
            ]} />
          ) : (
            <p className="muted">Jurnal penutupan belum dapat disimulasikan karena aturan posting belum terbit untuk tanggal penutupan.</p>
          )}
        </div>
      </Card>

      <Card flush title="Hasil per cabang dan unit bisnis">
        {preview.groups.length === 0 ? (
          <EmptyState title="Tidak ada saldo">Tidak ada akun pendapatan atau beban yang bersaldo.</EmptyState>
        ) : (
          <DataTable
            caption="Hasil tahun berjalan per cabang dan unit bisnis"
            rows={preview.groups}
            rowKey={(g) => `${g.branch_id ?? ''}|${g.business_unit_id ?? ''}`}
            scroll
            columns={[
              { header: 'Cabang', primary: true, cell: (g) => g.branch_code ?? 'Tanpa cabang' },
              { header: 'Unit bisnis', cell: (g) => g.business_unit_code ?? 'Tanpa unit bisnis' },
              { header: 'Pendapatan', align: 'right', cell: (g) => <Money value={g.revenue} /> },
              { header: 'Beban', align: 'right', cell: (g) => <Money value={g.expense} /> },
              { header: 'Hasil', align: 'right', cell: (g) => <Money value={g.result} strong /> },
            ]}
          />
        )}
      </Card>

      <Card flush title="Akun yang dipindahkan">
        {preview.accounts.length === 0 ? (
          <EmptyState title="Tidak ada akun">Tidak ada akun yang akan ditutup.</EmptyState>
        ) : (
          <DataTable
            caption="Akun pendapatan dan beban yang ditutup jurnal penutupan"
            rows={preview.accounts}
            rowKey={(a) => `${a.account_id}|${a.branch_code ?? ''}|${a.business_unit_code ?? ''}|${a.balance}`}
            scroll
            columns={[
              { header: 'Akun', primary: true, cell: (a) => <>{a.code} · {a.name}{a.status !== 'ACTIVE' && <> <Badge tone="warn">Nonaktif</Badge></>}</> },
              { header: 'Jenis', cell: (a) => accountTypeShort[a.account_type] ?? a.account_type },
              { header: 'Cabang / unit', cell: (a) => [a.branch_code, a.business_unit_code].filter(Boolean).join(' / ') || '—' },
              { header: 'Saldo', align: 'right', cell: (a) => <Money value={a.balance} /> },
              { header: 'Ditutup dengan', cell: (a) => sideLabels[a.closes_with] ?? a.closes_with },
              { header: 'Jumlah', align: 'right', cell: (a) => <Money value={a.amount} strong /> },
            ]}
          />
        )}
      </Card>
    </>
  )
}
