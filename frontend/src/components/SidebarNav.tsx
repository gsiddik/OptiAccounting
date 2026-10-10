import { useEffect, useId, useMemo, useState } from 'react'
import { matchPath, NavLink, useLocation } from 'react-router-dom'
import { Icon } from './Icon'
import type { NavItem } from './Shell'

type Section = { group?: string; items: NavItem[] }

/** Consecutive items of one group form a section; items without a group (the dashboard) stand alone. */
function sections(items: NavItem[]): Section[] {
  const result: Section[] = []
  for (const item of items) {
    const last = result[result.length - 1]
    if (item.group && last?.group === item.group) last.items.push(item)
    else result.push({ group: item.group, items: [item] })
  }
  return result
}

const storageKey = (scope: string) => `optientry.sidebar.collapsed.${scope}`

function readCollapsed(scope: string): Set<string> {
  try {
    const raw = window.localStorage.getItem(storageKey(scope))
    const parsed: unknown = raw ? JSON.parse(raw) : []
    return new Set(Array.isArray(parsed) ? parsed.filter((g): g is string => typeof g === 'string') : [])
  } catch {
    return new Set()
  }
}

function writeCollapsed(scope: string, collapsed: Set<string>) {
  try {
    window.localStorage.setItem(storageKey(scope), JSON.stringify([...collapsed]))
  } catch {
    // Private mode or blocked storage: the menu still works, it just does not remember.
  }
}

/**
 * The sidebar menu. Each group header expands and collapses its links (button with `aria-expanded`); the choice is remembered
 * per portal. The group that holds the current page is always opened when you arrive on it, so the active link is never hidden.
 */
export function SidebarNav({ scope, items, label }: { scope: 'platform' | 'tenant'; items: NavItem[]; label: string }) {
  const { pathname } = useLocation()
  const idBase = useId()
  const parts = useMemo(() => sections(items), [items])
  const headed = parts.flatMap((p) => (p.group ? [p.group] : []))
  const activeGroup = parts.find((p) => p.group && p.items.some((i) => matchPath({ path: i.to, end: i.end ?? false }, pathname)))?.group

  const [collapsed, setCollapsed] = useState(() => {
    const stored = readCollapsed(scope)
    if (activeGroup) stored.delete(activeGroup)
    return stored
  })
  // Arriving in another group (link, breadcrumb, back button) opens it; adjusted while rendering so no stale frame is shown.
  const [seenGroup, setSeenGroup] = useState(activeGroup)
  if (seenGroup !== activeGroup) {
    setSeenGroup(activeGroup)
    if (activeGroup && collapsed.has(activeGroup)) {
      const next = new Set(collapsed)
      next.delete(activeGroup)
      setCollapsed(next)
    }
  }
  useEffect(() => writeCollapsed(scope, collapsed), [scope, collapsed])

  const toggle = (group: string) => {
    const next = new Set(collapsed)
    if (!next.delete(group)) next.add(group)
    setCollapsed(next)
  }
  const allCollapsed = headed.length > 0 && headed.every((g) => collapsed.has(g))
  const toggleAll = () => setCollapsed(allCollapsed ? new Set() : new Set(headed))

  return (
    <nav aria-label={label} className="nav">
      {headed.length > 1 && (
        <button type="button" className="nav-toggle-all" onClick={toggleAll}>
          {allCollapsed ? 'Bentangkan semua' : 'Ciutkan semua'}
        </button>
      )}
      {parts.map((part, index) => {
        const links = part.items.map((item) => (
          <NavLink key={item.to} to={item.to} end={item.end} className={({ isActive }) => `nav-link${isActive ? ' active' : ''}`}>
            <Icon name={item.icon} />
            {item.label}
          </NavLink>
        ))
        if (!part.group) return <div key={`item-${index}`} className="nav-section">{links}</div>

        const open = !collapsed.has(part.group)
        const panel = `${idBase}-group-${index}`
        return (
          <div key={part.group} className="nav-section">
            <div className="nav-group">
              <button type="button" className={`nav-group-toggle${!open && part.group === activeGroup ? ' has-active' : ''}`} aria-expanded={open} aria-controls={panel} onClick={() => toggle(part.group as string)}>
                <span>{part.group}</span>
                <Icon name="chevron-down" size={14} />
              </button>
            </div>
            <div id={panel} className="nav-items" hidden={!open}>
              {links}
            </div>
          </div>
        )
      })}
    </nav>
  )
}
