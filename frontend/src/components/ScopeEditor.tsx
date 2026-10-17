import { useI18n } from '../i18n'
import { scopeLabel } from '../lib/labels'
import { SCOPE_TYPES, type ScopeDraft } from '../lib/scopes'
import type { Branch, BusinessUnit } from '../lib/types'
import { Button } from './ui'

/** Which data a user may reach. No rows means no data access at all. The API refuses scopes wider than the actor's own. */
export function ScopeEditor({ rows, onChange, branches, units }: { rows: ScopeDraft[]; onChange: (rows: ScopeDraft[]) => void; branches: Branch[]; units: BusinessUnit[] }) {
  const { t } = useI18n()
  const update = (index: number, patch: Partial<ScopeDraft>) => onChange(rows.map((r, i) => (i === index ? { ...r, ...patch } : r)))

  return (
    <fieldset className="field" style={{ margin: 0, padding: 0, border: 0, minWidth: 0 }}>
      <legend className="label" style={{ padding: 0, marginBottom: 6 }}>{t('scope.editor.legend')}</legend>
      <div className="stack" style={{ gap: 10 }}>
        {rows.length === 0 && <span className="hint">{t('scope.editor.none')}</span>}
        {rows.map((row, index) => (
          <div key={index} className="form-grid" style={{ alignItems: 'end', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr)) auto' }}>
            <div className="field">
              <label htmlFor={`scope-type-${index}`}>{t('scope.editor.type')}</label>
              <select id={`scope-type-${index}`} className="select" value={row.scope_type} onChange={(e) => update(index, { scope_type: e.target.value as ScopeDraft['scope_type'], branch_id: '', business_unit_id: '' })}>
                {SCOPE_TYPES.map((type) => <option key={type} value={type}>{scopeLabel(type)}</option>)}
              </select>
            </div>
            {row.scope_type === 'BRANCH' && (
              <div className="field">
                <label htmlFor={`scope-branch-${index}`}>{t('scope.editor.branch')}</label>
                <select id={`scope-branch-${index}`} className="select" value={row.branch_id} onChange={(e) => update(index, { branch_id: e.target.value })}>
                  <option value="">{t('scope.editor.pick_branch')}</option>
                  {branches.map((b) => <option key={b.id} value={b.id}>{b.code} · {b.name}</option>)}
                </select>
              </div>
            )}
            {row.scope_type === 'BUSINESS_UNIT' && (
              <div className="field">
                <label htmlFor={`scope-unit-${index}`}>{t('scope.editor.unit')}</label>
                <select id={`scope-unit-${index}`} className="select" value={row.business_unit_id} onChange={(e) => update(index, { business_unit_id: e.target.value })}>
                  <option value="">{t('scope.editor.pick_unit')}</option>
                  {units.map((u) => <option key={u.id} value={u.id}>{u.code} · {u.name}</option>)}
                </select>
              </div>
            )}
            <Button size="sm" variant="ghost" aria-label={t('scope.editor.remove_n', { n: index + 1 })} onClick={() => onChange(rows.filter((_, i) => i !== index))}>{t('common.delete')}</Button>
          </div>
        ))}
        <div>
          <Button size="sm" onClick={() => onChange([...rows, { scope_type: 'TENANT', branch_id: '', business_unit_id: '' }])}>{t('scope.editor.add')}</Button>
        </div>
      </div>
    </fieldset>
  )
}
