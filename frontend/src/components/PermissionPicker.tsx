import { groupLabels } from '../lib/labels'
import type { Permission } from '../lib/types'

/** Permission checkboxes grouped by area. Selecting is cosmetic: the API refuses permissions the actor does not hold. */
export function PermissionPicker({
  permissions,
  selected,
  onChange,
  disabled,
  held,
}: {
  permissions: Permission[]
  selected: Set<string>
  onChange: (next: Set<string>) => void
  disabled?: boolean
  /** Codes the current user may grant; others are shown disabled. Undefined = no restriction hint. */
  held?: Set<string>
}) {
  const groups = new Map<string, Permission[]>()
  for (const p of permissions) groups.set(p.group, [...(groups.get(p.group) ?? []), p])

  function toggle(code: string, on: boolean) {
    const next = new Set(selected)
    if (on) next.add(code)
    else next.delete(code)
    onChange(next)
  }

  return (
    <div className="stack" style={{ gap: 12 }}>
      {[...groups.entries()].map(([group, items]) => (
        <fieldset key={group} className="perm-group" style={{ margin: 0, padding: 0, minWidth: 0 }} disabled={disabled}>
          <h3 style={{ margin: 0 }}>{groupLabels[group] ?? group}</h3>
          <div className="perm-list">
            {items.map((p) => (
              <label key={p.code} className="check">
                <input type="checkbox" checked={selected.has(p.code)} disabled={held ? !held.has(p.code) && !selected.has(p.code) : false} onChange={(e) => toggle(p.code, e.target.checked)} />
                <span>
                  <span className="mono">{p.code}</span>
                  <small>{p.description}</small>
                </span>
              </label>
            ))}
          </div>
        </fieldset>
      ))}
    </div>
  )
}
