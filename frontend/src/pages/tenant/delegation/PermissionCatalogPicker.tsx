import { Badge } from '../../../components/ui'
import { hasMessage, useI18n } from '../../../i18n'
import type { CatalogPermission } from './types'

/**
 * Permissions of this application, grouped by module, with what OptiNexus will do with each: only `AVAILABLE` ones can be put in
 * a custom role; the others are listed (so nobody wonders where they went) but cannot be ticked.
 */
export function PermissionCatalogPicker({ permissions, selected, onChange, readOnly }: { permissions: CatalogPermission[]; selected: Set<string>; onChange?: (next: Set<string>) => void; readOnly?: boolean }) {
  const { t } = useI18n()
  const groups = new Map<string, CatalogPermission[]>()
  for (const p of permissions) groups.set(p.group, [...(groups.get(p.group) ?? []), p])

  function toggle(code: string, on: boolean) {
    const next = new Set(selected)
    if (on) next.add(code)
    else next.delete(code)
    onChange?.(next)
  }

  return (
    <div className="stack" style={{ gap: 12 }}>
      {[...groups.entries()].map(([group, items]) => {
        const groupKey = `group.${group}`
        return (
          <fieldset key={group} className="perm-group" style={{ margin: 0, padding: 0, minWidth: 0 }}>
            <h3 style={{ margin: 0 }}>{hasMessage(groupKey) ? t(groupKey) : group}</h3>
            <div className="perm-list">
              {items.map((p) => {
                const available = p.state === 'AVAILABLE'
                return (
                  <label key={p.code} className="check" data-state={p.state} style={available ? undefined : { opacity: 0.65 }}>
                    <input type="checkbox" checked={selected.has(p.code)} disabled={readOnly || !available} onChange={(e) => toggle(p.code, e.target.checked)} />
                    <span>
                      <span className="mono">{p.code}</span>
                      {!available && <> <Badge tone="neutral">{t(`roles.state.${p.state}`)}</Badge></>}
                      <small>{p.description}</small>
                    </span>
                  </label>
                )
              })}
            </div>
          </fieldset>
        )
      })}
    </div>
  )
}
