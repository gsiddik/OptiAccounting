import type { Role } from '../lib/types'

/** Pick any number of roles. The API refuses roles the actor may not hand out; this only presents the list. */
export function RoleChecklist({ roles, selected, onChange, legend = 'Peran' }: { roles: Role[]; selected: Set<string>; onChange: (next: Set<string>) => void; legend?: string }) {
  function toggle(id: string, on: boolean) {
    const next = new Set(selected)
    if (on) next.add(id)
    else next.delete(id)
    onChange(next)
  }

  return (
    <fieldset className="field" style={{ margin: 0, padding: 0, border: 0, minWidth: 0 }}>
      <legend className="label" style={{ padding: 0, marginBottom: 6 }}>{legend}</legend>
      <div className="perm-group">
        <div className="perm-list">
          {roles.map((r) => (
            <label key={r.id} className="check">
              <input type="checkbox" checked={selected.has(r.id)} onChange={(e) => toggle(r.id, e.target.checked)} />
              <span>
                {r.name}
                {r.description && <small>{r.description}</small>}
              </span>
            </label>
          ))}
        </div>
      </div>
    </fieldset>
  )
}
