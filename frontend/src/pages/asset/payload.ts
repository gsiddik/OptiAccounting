import { amountToApi, parseAmount } from '../../lib/accounting'
import { amountInput } from '../operational/expense/payload'
import type { Asset, AssetCategory, AssetMethod, CapitalizationMode, Disposal, DisposalType, ResidualType, StartPolicy } from './types'

// Form state of the three asset forms and the request bodies built from it. No accounting math lives here: amounts are validated as plain decimals and
// sent as strings; the API recomputes every figure (basis, schedule, book value, gain or loss) and refuses what it cannot accept.

export type Built = { body: Record<string, unknown> } | { problem: string }

const AMOUNT_PROBLEM = 'harus berupa angka tanpa pemisah ribuan, dengan maksimal empat desimal.'

/** A whole number of months between 1 and 1200 as a number, '' when empty, or null when it is not one. */
function months(input: string): number | '' | null {
  const text = input.trim()
  if (text === '') return ''
  if (!/^\d{1,4}$/.test(text)) return null
  const n = Number(text)
  return n >= 1 && n <= 1200 ? n : null
}

const LIFE_PROBLEM = 'Umur manfaat harus bilangan bulat antara 1 dan 1200 bulan.'

// ------------------------------------------------------------------------------------------------ category

export type CategoryForm = {
  code: string
  name: string
  description: string
  default_method: AssetMethod
  default_useful_life_months: string
  default_residual_type: ResidualType
  default_residual_value: string
  default_start_policy: StartPolicy
  asset_account_id: string
  accumulated_account_id: string
  expense_account_id: string
  gain_loss_account_id: string
}

export const emptyCategoryForm = (): CategoryForm => ({
  code: '', name: '', description: '', default_method: 'STRAIGHT_LINE', default_useful_life_months: '', default_residual_type: 'NONE', default_residual_value: '',
  default_start_policy: 'CAPITALIZATION_MONTH', asset_account_id: '', accumulated_account_id: '', expense_account_id: '', gain_loss_account_id: '',
})

export const categoryFormFrom = (c: AssetCategory): CategoryForm => ({
  code: c.code, name: c.name, description: c.description ?? '', default_method: c.default_method,
  default_useful_life_months: c.default_useful_life_months ? String(c.default_useful_life_months) : '',
  default_residual_type: c.default_residual_type, default_residual_value: c.default_residual_type === 'NONE' ? '' : amountInput(c.default_residual_value),
  default_start_policy: c.default_start_policy, asset_account_id: c.asset_account_id ?? '', accumulated_account_id: c.accumulated_account_id ?? '',
  expense_account_id: c.expense_account_id ?? '', gain_loss_account_id: c.gain_loss_account_id ?? '',
})

/** Every account key is always sent (null clears an override), so the category never keeps an account the user removed. The code is sent on creation only. */
export function buildCategoryBody(f: CategoryForm, creating: boolean): Built {
  if (creating && f.code.trim() === '') return { problem: 'Kode kategori wajib diisi.' }
  if (f.name.trim() === '') return { problem: 'Nama kategori wajib diisi.' }
  const life = f.default_method === 'NONE' ? '' : months(f.default_useful_life_months)
  if (life === null) return { problem: LIFE_PROBLEM }
  const body: Record<string, unknown> = {
    name: f.name.trim(),
    description: f.description.trim() || null,
    default_method: f.default_method,
    default_useful_life_months: life === '' ? null : life,
    default_residual_type: f.default_residual_type,
    default_start_policy: f.default_start_policy,
    asset_account_id: f.asset_account_id || null,
    accumulated_account_id: f.accumulated_account_id || null,
    expense_account_id: f.expense_account_id || null,
    gain_loss_account_id: f.gain_loss_account_id || null,
  }
  if (f.default_residual_type !== 'NONE') {
    const value = parseAmount(f.default_residual_value)
    if (value === null) return { problem: `Nilai sisa default ${AMOUNT_PROBLEM}` }
    body.default_residual_value = amountToApi(value)
  }
  if (creating) body.code = f.code.trim()
  return { body }
}

// ------------------------------------------------------------------------------------------------ asset

export type AssetForm = {
  asset_category_id: string
  name: string
  description: string
  acquisition_date: string
  capitalization_date: string
  acquisition_cost: string
  residual_value: string
  useful_life_months: string
  /** '' = follow the category default. */
  method: '' | AssetMethod
  start_policy: '' | StartPolicy
  factor: string
  capitalization_mode: CapitalizationMode
  source_account_id: string
  ap_invoice_line_id: string
  source_reference: string
  branch_id: string
  business_unit_id: string
  cost_center_id: string
}

export const emptyAssetForm = (today: string, categoryId = ''): AssetForm => ({
  asset_category_id: categoryId, name: '', description: '', acquisition_date: today, capitalization_date: '', acquisition_cost: '', residual_value: '', useful_life_months: '',
  method: '', start_policy: '', factor: '', capitalization_mode: 'POST', source_account_id: '', ap_invoice_line_id: '', source_reference: '', branch_id: '', business_unit_id: '', cost_center_id: '',
})

export const assetFormFrom = (a: Asset): AssetForm => ({
  asset_category_id: a.asset_category_id, name: a.name, description: a.description ?? '', acquisition_date: a.acquisition_date.slice(0, 10), capitalization_date: a.capitalization_date.slice(0, 10),
  acquisition_cost: amountInput(a.acquisition_cost), residual_value: amountInput(a.residual_value), useful_life_months: a.useful_life_months ? String(a.useful_life_months) : '',
  method: a.method, start_policy: a.start_policy, factor: a.method_params?.factor ?? '', capitalization_mode: a.capitalization_mode,
  source_account_id: a.source_account_id ?? '', ap_invoice_line_id: a.source_type === 'AP_INVOICE_LINE' ? a.source_id ?? '' : '', source_reference: a.source_reference ?? '',
  branch_id: a.branch_id ?? '', business_unit_id: a.business_unit_id ?? '', cost_center_id: a.cost_center_id ?? '',
})

/** The method the asset will use: its own choice, or the category default while the choice is left open. */
export const effectiveMethod = (f: Pick<AssetForm, 'method'>, category: Pick<AssetCategory, 'default_method'> | undefined): AssetMethod | '' => f.method || category?.default_method || ''

/**
 * Keys the user left open are not sent, so the API applies the category default (life, method, start policy, and on creation the residual policy).
 * An empty capitalization date is not sent either: the API then takes the acquisition date. `existing` is true when an existing draft is being edited.
 */
export function buildAssetBody(f: AssetForm, category: AssetCategory | undefined, existing: boolean): Built {
  if (!f.asset_category_id) return { problem: 'Pilih kategori aset.' }
  if (f.name.trim() === '') return { problem: 'Nama aset wajib diisi.' }
  if (!f.acquisition_date) return { problem: 'Tanggal perolehan wajib diisi.' }
  const cost = parseAmount(f.acquisition_cost)
  if (cost === null) return { problem: `Harga perolehan ${AMOUNT_PROBLEM}` }
  if (cost === 0n) return { problem: 'Harga perolehan harus lebih besar dari nol.' }
  const residual = parseAmount(f.residual_value)
  if (residual === null) return { problem: `Nilai sisa ${AMOUNT_PROBLEM}` }
  const life = months(f.useful_life_months)
  if (life === null) return { problem: LIFE_PROBLEM }
  if (f.capitalization_mode === 'REGISTER_ONLY' && !f.ap_invoice_line_id.trim()) return { problem: 'Pilih baris faktur vendor yang biayanya sudah dijurnal.' }

  const body: Record<string, unknown> = {
    asset_category_id: f.asset_category_id,
    name: f.name.trim(),
    description: f.description.trim() || null,
    acquisition_date: f.acquisition_date,
    acquisition_cost: amountToApi(cost),
    capitalization_mode: f.capitalization_mode,
    source_reference: f.source_reference.trim() || null,
    branch_id: f.branch_id || null,
    business_unit_id: f.business_unit_id || null,
    cost_center_id: f.cost_center_id || null,
  }
  if (f.capitalization_date) body.capitalization_date = f.capitalization_date
  if (f.residual_value.trim() !== '' || existing) body.residual_value = amountToApi(residual)
  if (life !== '') body.useful_life_months = life
  if (f.method) body.method = f.method
  if (f.start_policy) body.start_policy = f.start_policy
  if (effectiveMethod(f, category) === 'DECLINING_BALANCE' && f.factor.trim() !== '') body.method_params = { factor: f.factor.trim().replace(',', '.') }
  if (f.capitalization_mode === 'POST') body.source_account_id = f.source_account_id || null
  else body.ap_invoice_line_id = f.ap_invoice_line_id.trim()
  return { body }
}

// ------------------------------------------------------------------------------------------------ disposal

export type DisposalForm = {
  fixed_asset_id: string
  disposal_type: DisposalType
  disposal_date: string
  document_date: string
  posting_date: string
  proceeds_amount: string
  proceeds_account_id: string
  reason: string
  reference: string
}

export const emptyDisposalForm = (today: string, assetId = ''): DisposalForm => ({
  fixed_asset_id: assetId, disposal_type: 'SALE', disposal_date: today, document_date: today, posting_date: today, proceeds_amount: '', proceeds_account_id: '', reason: '', reference: '',
})

export const disposalFormFrom = (d: Disposal): DisposalForm => ({
  fixed_asset_id: d.fixed_asset_id, disposal_type: d.disposal_type, disposal_date: d.disposal_date.slice(0, 10), document_date: d.document_date.slice(0, 10), posting_date: d.posting_date.slice(0, 10),
  proceeds_amount: amountInput(d.proceeds_amount), proceeds_account_id: d.proceeds_account_id ?? '', reason: d.reason, reference: d.reference ?? '',
})

/** A SALE sends its proceeds and receiving account; a SCRAP sends none (the API requires proceeds above zero for a sale only). */
export function buildDisposalBody(f: DisposalForm): Built {
  if (!f.fixed_asset_id) return { problem: 'Pilih aset yang akan dilepas.' }
  if (!f.disposal_date) return { problem: 'Tanggal pelepasan wajib diisi.' }
  if (f.reason.trim() === '') return { problem: 'Alasan pelepasan wajib diisi.' }
  const body: Record<string, unknown> = {
    fixed_asset_id: f.fixed_asset_id,
    disposal_type: f.disposal_type,
    disposal_date: f.disposal_date,
    document_date: f.document_date || null,
    posting_date: f.posting_date || null,
    reason: f.reason.trim(),
    reference: f.reference.trim() || null,
  }
  if (f.disposal_type === 'SALE') {
    const proceeds = parseAmount(f.proceeds_amount)
    if (proceeds === null) return { problem: `Hasil penjualan ${AMOUNT_PROBLEM}` }
    if (proceeds === 0n) return { problem: 'Penjualan membutuhkan hasil penjualan lebih besar dari nol; gunakan penghapusan untuk aset yang dibuang.' }
    if (!f.proceeds_account_id) return { problem: 'Pilih akun penerimaan hasil penjualan.' }
    body.proceeds_amount = amountToApi(proceeds)
    body.proceeds_account_id = f.proceeds_account_id
  } else {
    body.proceeds_amount = '0.0000'
    body.proceeds_account_id = null
  }
  return { body }
}
