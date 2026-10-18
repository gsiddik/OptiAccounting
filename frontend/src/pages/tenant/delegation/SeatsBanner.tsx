import { Banner } from '../../../components/ui'
import { useI18n, type MessageKey } from '../../../i18n'
import type { Seats } from './types'

/**
 * The user seats of this organization as OptiNexus counted them (contract 2d): used, reserved by open invitations, the limit and what is
 * left. Every figure is OptiNexus' own; nothing is calculated here except choosing the wording. A limit that could not be decided is said
 * so, never shown as unlimited. The server still decides every addition, this only explains it.
 */
export function SeatsBanner({ seats }: { seats: Seats | null | undefined }) {
  const { t } = useI18n()
  if (!seats) return null

  const figures = { used: seats.used, reserved: seats.reserved, limit: seats.limit ?? '', available: seats.available ?? '', over_by: seats.over_by }

  if (seats.state === 'NOT_CONTROLLED') return <Banner tone="info">{t('seats.not_controlled', figures)}</Banner>
  if (seats.state === 'UNLIMITED') return <Banner tone="info">{t('seats.unlimited', figures)}</Banner>
  if (seats.state === 'LIMITED') {
    if (seats.over_limit) {
      return <Banner tone="warn"><strong>{t('seats.over.title', figures)}</strong> {t('seats.over.body', figures)}</Banner>
    }
    return (
      <Banner tone={seats.can_add ? 'info' : 'warn'}>
        {t('seats.summary', figures)}
        {!seats.can_add && <> {t('seats.full')}</>}
      </Banner>
    )
  }
  // ENTITLEMENT_MISSING | ENTITLEMENT_INVALID | ENTITLEMENT_UNAVAILABLE
  return (
    <Banner tone="warn">
      {t('seats.used_only', figures)} {t(`seats.undecided.${seats.state}` as MessageKey)}
    </Banner>
  )
}
