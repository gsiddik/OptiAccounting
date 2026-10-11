import { useMemo } from 'react'
import type { Account, AccountType } from '../../lib/accounting'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { useResource } from '../../lib/hooks'
import { API, MODULES, type Page } from '../../lib/operational'
import { accountLabel } from '../accounting/data'
import type { AccountRef, ApInvoiceOption, ApLineOption, Asset, AssetCategory } from './types'

export const PATHS = {
  assets: '/app/akuntansi/aset',
  categories: '/app/akuntansi/kategori-aset',
  runs: '/app/akuntansi/penyusutan',
  disposals: '/app/akuntansi/pelepasan-aset',
  journal: '/app/akuntansi/jurnal',
} as const

export type Option = { id: string; label: string }

/** Asset categories for a select box (up to 100 by code). */
export function useAssetCategories(status?: 'ACTIVE') {
  const r = useResource(async () => (await api.get<Page<AssetCategory>>(`${API}/asset-categories`, { params: { per_page: 100, ...(status && { status }) } })).data.data, [status])
  return { ...r, categories: r.data ?? [] }
}

/**
 * Accounts a field may take, as the API accepts them: active, postable, not a control account, of one of the given types. The account the record
 * already uses stays selectable (it may have been deactivated since) and can be passed as `current`.
 */
export function accountOptions(accounts: Account[], types: AccountType[], current: (AccountRef | null | undefined)[] = []): Option[] {
  const list = accounts
    .filter((a) => a.status === 'ACTIVE' && a.is_postable && !a.is_control && types.includes(a.account_type))
    .map((a) => ({ id: a.id, label: accountLabel(a) }))
  for (const c of current) if (c && !list.some((o) => o.id === c.id)) list.push({ id: c.id, label: accountLabel(c) })
  return list
}

/** Assets that may still be disposed of (active or fully depreciated), matching an optional search text. Two small requests, one per status. */
export function useDisposableAssets(q: string) {
  const r = useResource(async () => {
    const get = async (status: string) => (await api.get<Page<Asset>>(`${API}/assets`, { params: { status, per_page: 100, ...(q && { q }) } })).data.data
    const [active, full] = await Promise.all([get('ACTIVE'), get('FULLY_DEPRECIATED')])
    return [...active, ...full].sort((a, b) => (a.asset_number ?? '').localeCompare(b.asset_number ?? ''))
  }, [q])
  return { ...r, assets: r.data ?? [] }
}

/** One asset by id (for a disposal started from the asset's own page). */
export function useAssetById(id: string | null) {
  const r = useResource(async () => (id ? (await api.get<Asset>(`${API}/assets/${id}`)).data : null), [id])
  return { ...r, asset: r.data }
}

/** Whether this user can browse posted vendor invoices to register an asset from one of their lines (permission, module and feature). */
export function useCanPickInvoiceLine(): boolean {
  const { can, moduleMode, featureEnabled } = useCapabilities()
  return can('accounting.ap_invoice.view') && moduleMode(MODULES.ap) !== 'NONE' && featureEnabled('VENDOR_INVOICE')
}

/** Posted vendor invoices matching a search text, newest first. */
export function usePostedInvoices(q: string, enabled: boolean) {
  const r = useResource(async () => (enabled ? (await api.get<Page<ApInvoiceOption>>(`${API}/ap-invoices`, { params: { status: 'POSTED', per_page: 20, ...(q && { q }) } })).data.data : []), [q, enabled])
  return { ...r, invoices: r.data ?? [] }
}

/** The lines of one vendor invoice that name an account (only those can carry an asset). */
export function useInvoiceLines(invoiceId: string) {
  const r = useResource(async () => (invoiceId ? (await api.get<{ lines?: ApLineOption[] }>(`${API}/ap-invoices/${invoiceId}`)).data.lines ?? [] : []), [invoiceId])
  const lines = useMemo(() => (r.data ?? []).filter((l) => l.account_id), [r.data])
  return { ...r, lines }
}
