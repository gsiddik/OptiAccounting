import type { TaxCode } from './types'

/** Where a code posts its tax: an account, a role, or nothing for a cost (non-recoverable) tax. */
export function DestinationText({ code }: { code: Pick<TaxCode, 'account' | 'account_id' | 'account_role' | 'is_recoverable'> }) {
  if (!code.is_recoverable) return <span className="muted">Menjadi biaya (tanpa akun pajak)</span>
  if (code.account) return <><span className="mono">{code.account.code}</span> · {code.account.name}</>
  if (code.account_id) return <span className="muted">Akun tertentu</span>
  if (code.account_role) return <>Peran <span className="mono">{code.account_role}</span></>
  return <span className="muted">—</span>
}

/** "Ya" / "Tidak" for an input tax; output and withholding taxes are always owed, so the question does not apply. */
export function RecoverableText({ code }: { code: Pick<TaxCode, 'tax_type' | 'is_recoverable'> }) {
  if (code.tax_type === 'OUTPUT_TAX' || code.tax_type === 'WITHHOLDING') return <span className="muted">—</span>
  return <>{code.is_recoverable ? 'Ya' : 'Tidak'}</>
}
