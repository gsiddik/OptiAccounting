import { generatePath, matchPath } from 'react-router-dom'

/** One step of the breadcrumb trail. Without `to` it is plain text (the current page, or a sidebar group with no page of its own). */
export type Crumb = { label: string; to?: string }

type Meta = { label: string; /** Sidebar group shown before the page, as in the menu. */ group?: string; parent?: string }

const HOME_LABEL = 'Beranda'
const ACCOUNTING = '/app/akuntansi'
/** Groups that have a landing page of their own; every other group is only a label. */
const GROUP_LINKS: Record<string, string> = { Akuntansi: ACCOUNTING }

const META: Record<string, Meta> = {}
const page = (path: string, label: string, group?: string, parent?: string) => {
  META[path] = { label, group, parent }
}
/** Detail, create and edit pages of a document list. `editor: false` for read-only detail pages. */
const documentPages = (list: string, editor = true) => {
  page(`${list}/:id`, 'Detail', undefined, list)
  if (editor) {
    page(`${list}/baru`, 'Baru', undefined, list)
    page(`${list}/:id/ubah`, 'Ubah', undefined, `${list}/:id`)
  }
}

// Platform portal
page('/platform', 'Beranda')
page('/platform/tenants', 'Tenant', 'Pelanggan')
documentPages('/platform/tenants', false)
page('/platform/modules', 'Modul & fitur', 'Katalog')
page('/platform/bundles', 'Paket', 'Katalog')
page('/platform/operators', 'Operator & akses', 'Platform')
page('/platform/audit', 'Audit', 'Platform')

// Tenant portal
page('/app', 'Beranda')
page('/app/organisasi', 'Organisasi', 'Pengaturan')
page('/app/pengguna', 'Pengguna', 'Pengaturan')
page('/app/peran', 'Peran & izin', 'Pengaturan')
page('/app/langganan', 'Langganan', 'Akun')
page('/app/penggunaan', 'Penggunaan & batas', 'Akun')
page('/app/audit', 'Audit', 'Akun')

page(ACCOUNTING, 'Akuntansi')
page(`${ACCOUNTING}/jurnal`, 'Jurnal', 'Akuntansi')
documentPages(`${ACCOUNTING}/jurnal`)
page(`${ACCOUNTING}/buku-besar`, 'Buku besar', 'Akuntansi')
page(`${ACCOUNTING}/neraca-saldo`, 'Neraca saldo', 'Akuntansi')
page(`${ACCOUNTING}/saldo-awal`, 'Saldo awal', 'Akuntansi')

page(`${ACCOUNTING}/vendor`, 'Vendor', 'Utang usaha')
page(`${ACCOUNTING}/faktur-vendor`, 'Faktur vendor', 'Utang usaha')
documentPages(`${ACCOUNTING}/faktur-vendor`)
page(`${ACCOUNTING}/pembayaran-vendor`, 'Pembayaran vendor', 'Utang usaha')
documentPages(`${ACCOUNTING}/pembayaran-vendor`)
page(`${ACCOUNTING}/umur-utang`, 'Umur utang', 'Utang usaha')

page(`${ACCOUNTING}/pelanggan`, 'Pelanggan', 'Piutang')
page(`${ACCOUNTING}/faktur-pelanggan`, 'Faktur pelanggan', 'Piutang')
documentPages(`${ACCOUNTING}/faktur-pelanggan`)
page(`${ACCOUNTING}/penerimaan-pelanggan`, 'Penerimaan pelanggan', 'Piutang')
documentPages(`${ACCOUNTING}/penerimaan-pelanggan`)
page(`${ACCOUNTING}/nota-kredit`, 'Nota kredit', 'Piutang')
documentPages(`${ACCOUNTING}/nota-kredit`)
page(`${ACCOUNTING}/umur-piutang`, 'Umur piutang', 'Piutang')

page(`${ACCOUNTING}/beban`, 'Beban', 'Beban')
documentPages(`${ACCOUNTING}/beban`)
page(`${ACCOUNTING}/kategori-beban`, 'Kategori beban', 'Beban')

page(`${ACCOUNTING}/kas-bank`, 'Akun kas & bank', 'Kas & bank')
documentPages(`${ACCOUNTING}/kas-bank`, false)
page(`${ACCOUNTING}/pembayaran-kas`, 'Pembayaran kas', 'Kas & bank')
documentPages(`${ACCOUNTING}/pembayaran-kas`)
page(`${ACCOUNTING}/penerimaan-kas`, 'Penerimaan kas', 'Kas & bank')
documentPages(`${ACCOUNTING}/penerimaan-kas`)
page(`${ACCOUNTING}/rekening-koran`, 'Rekening koran', 'Kas & bank')
documentPages(`${ACCOUNTING}/rekening-koran`, false)

page(`${ACCOUNTING}/rekonsiliasi/utang`, 'Utang vs buku besar', 'Rekonsiliasi')
page(`${ACCOUNTING}/rekonsiliasi/piutang`, 'Piutang vs buku besar', 'Rekonsiliasi')
page(`${ACCOUNTING}/rekonsiliasi/kas-bank`, 'Kas/bank vs buku besar', 'Rekonsiliasi')

page(`${ACCOUNTING}/profil`, 'Profil akuntansi', 'Konfigurasi akuntansi')
page(`${ACCOUNTING}/periode`, 'Tahun fiskal & periode', 'Konfigurasi akuntansi')
page(`${ACCOUNTING}/akun`, 'Bagan akun', 'Konfigurasi akuntansi')
page(`${ACCOUNTING}/aturan-posting`, 'Aturan posting', 'Konfigurasi akuntansi')
page(`${ACCOUNTING}/pemetaan-akun`, 'Pemetaan akun', 'Konfigurasi akuntansi')

/** Every route pattern that has breadcrumb metadata (a test keeps this in step with the routes in App.tsx). */
export const BREADCRUMB_PATTERNS = Object.keys(META)

const isDynamic = (pattern: string) => /\/:[^/]+$/.test(pattern)

function homeOf(pathname: string): string | null {
  if (pathname === '/platform' || pathname.startsWith('/platform/')) return '/platform'
  if (pathname === '/app' || pathname.startsWith('/app/')) return '/app'
  return null
}

/** The most specific route pattern that matches: fixed paths such as `/baru` win over `/:id`. */
function resolvePattern(pathname: string): { pattern: string; params: Record<string, string> } | null {
  const trimmed = pathname.length > 1 ? pathname.replace(/\/+$/, '') : pathname
  const ordered = [...BREADCRUMB_PATTERNS].sort((a, b) => Number(a.includes(':')) - Number(b.includes(':')))
  for (const pattern of ordered) {
    const match = matchPath({ path: pattern, end: true }, trimmed)
    if (match) return { pattern, params: Object.fromEntries(Object.entries(match.params).map(([k, v]) => [k, v ?? ''])) }
  }
  return null
}

/**
 * Breadcrumb trail of a page: portal home, the sidebar group, the ancestors, then the page itself (no link).
 * `dynamicLabels` holds the titles pages announced for their own address, so a detail page reads as its document
 * number instead of "Detail" (see `CrumbLabelProvider`).
 */
export function buildTrail(pathname: string, dynamicLabels: Record<string, string> = {}): Crumb[] {
  const home = homeOf(pathname)
  if (!home) return []
  const resolved = resolvePattern(pathname)
  if (!resolved) return [{ label: HOME_LABEL, to: home }, { label: 'Halaman tidak ditemukan' }]

  const chain: string[] = []
  for (let pattern: string | undefined = resolved.pattern; pattern; pattern = META[pattern].parent) chain.unshift(pattern)

  const trail: Crumb[] = []
  if (chain[0] !== home) trail.push({ label: HOME_LABEL, to: home })
  const group = META[chain[0]].group
  if (group) trail.push({ label: group, to: GROUP_LINKS[group] })
  for (const pattern of chain) {
    const to = generatePath(pattern, resolved.params)
    trail.push({ label: (isDynamic(pattern) ? dynamicLabels[to] : undefined) ?? META[pattern].label, to })
  }
  // The current page and the portal home are never links to themselves.
  const last = trail[trail.length - 1]
  trail[trail.length - 1] = { label: last.label }
  return trail
}

/** Where "back" leads when the browser history of this portal is empty: the nearest ancestor that is a page. */
export function parentOf(trail: Crumb[]): string | null {
  for (let i = trail.length - 2; i >= 0; i--) if (trail[i].to) return trail[i].to ?? null
  return null
}
