import { Badge } from '../../../components/ui'
import { directionLabels } from '../../../lib/operationalLabels'
import { Money } from '../shared'
import { directionOf, unsigned } from './amounts'

/** A signed statement amount shown the way people read a bank statement: the figure and "Masuk" (deposit) or "Keluar" (withdrawal). */
export function DirectionAmount({ amount }: { amount: string }) {
  const direction = directionOf(amount)
  return (
    <span style={{ display: 'inline-flex', gap: 8, alignItems: 'center', justifyContent: 'flex-end' }}>
      <Money value={unsigned(amount)} />
      <Badge tone={direction === 'IN' ? 'ok' : 'neutral'}>{directionLabels[direction]}</Badge>
    </span>
  )
}
