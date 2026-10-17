import { Link } from 'react-router-dom'
import { navLabel, useI18n } from '../i18n'
import type { Crumb } from '../lib/breadcrumbs'
import { Icon } from './Icon'

/** "Kembali": the previous page of this portal, or the parent page when there is none (see Shell). */
export function BackButton({ onBack }: { onBack: () => void }) {
  useI18n()
  return (
    <button type="button" className="btn btn-sm back-btn" onClick={onBack} aria-label={navLabel('Kembali ke halaman sebelumnya')}>
      <Icon name="arrow-left" size={16} />
      <span>{navLabel('Kembali')}</span>
    </button>
  )
}

/** Where the current page sits: every step but the last is a link, the last is the current page. */
export function Breadcrumbs({ trail }: { trail: Crumb[] }) {
  useI18n()
  return (
    <nav aria-label="Breadcrumb" className="breadcrumbs">
      <ol>
        {trail.map((crumb, index) => {
          const current = index === trail.length - 1
          return (
            <li key={`${index}-${crumb.label}`}>
              {crumb.to && !current ? <Link to={crumb.to}>{navLabel(crumb.label)}</Link> : <span aria-current={current ? 'page' : undefined}>{navLabel(crumb.label)}</span>}
            </li>
          )
        })}
      </ol>
    </nav>
  )
}
