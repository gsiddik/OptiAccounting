import { formatAmount } from '../../lib/accounting'
import { assetMethodLabels, residualTypeLabels, startPolicyLabels } from '../../lib/oa4Labels'
import type { AssetCategory } from './types'

// Indonesian wording that only the asset pages use. Shared option labels (method, residual type, start policy, capitalization mode, disposal type,
// posting rule events) live in lib/oa4Labels.ts and are imported from there.

export const assetRoleLabels: Record<string, string> = {
  FIXED_ASSET: 'Aset tetap',
  ACCUMULATED_DEPRECIATION: 'Akumulasi penyusutan',
  DEPRECIATION_EXPENSE: 'Beban penyusutan',
  ASSET_DISPOSAL_GAIN_LOSS: 'Laba/rugi pelepasan aset',
}

export const ruleSkipLabels: Record<string, string> = {
  ALREADY_PUBLISHED: 'sudah ada aturan terbit',
  CODE_TAKEN: 'kode aturan standar sudah dipakai',
}

export const methodText = (method: string | null | undefined): string => (method ? assetMethodLabels[method] ?? method : '—')

/** The category's residual policy as one phrase, e.g. "Persentase dari biaya: 10". The value is shown as the API stored it. */
export function residualPolicyText(c: Pick<AssetCategory, 'default_residual_type' | 'default_residual_value'>): string {
  if (c.default_residual_type === 'NONE') return residualTypeLabels.NONE
  const value = c.default_residual_type === 'PERCENT' ? `${formatAmount(c.default_residual_value)}%` : formatAmount(c.default_residual_value)
  return `${residualTypeLabels[c.default_residual_type] ?? c.default_residual_type}: ${value}`
}

/** "Garis lurus · 48 bulan · mulai bulan kapitalisasi" for a category's depreciation defaults. */
export function categoryDefaultsText(c: Pick<AssetCategory, 'default_method' | 'default_useful_life_months' | 'default_start_policy'>): string {
  const life = c.default_useful_life_months ? ` · ${c.default_useful_life_months} bulan` : ''
  return `${methodText(c.default_method)}${life} · mulai ${(startPolicyLabels[c.default_start_policy] ?? c.default_start_policy).toLowerCase()}`
}

/** A zero decimal string ("0", "0.0000"): used only to decide whether to show a figure or a dash, never to compute. */
export const isZeroAmount = (value: string | null | undefined): boolean => !value || /^-?0+(\.0+)?$/.test(value)
