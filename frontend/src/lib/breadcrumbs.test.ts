import { describe, expect, it } from 'vitest'
import appSource from '../App.tsx?raw'
import { BREADCRUMB_PATTERNS, buildTrail, parentOf } from './breadcrumbs'

/** Full paths of the portal routes declared in App.tsx (nesting follows the opening and closing <Route> tags). */
function appRoutes(source: string): string[] {
  const open: string[] = []
  const found: string[] = []
  for (const line of source.split('\n').map((l) => l.trim())) {
    if (line.startsWith('</Route>')) {
      open.pop()
      continue
    }
    const match = line.match(/^<Route (?:path="([^"]*)"|index)/)
    if (!match) continue
    const parent = open[open.length - 1] ?? ''
    const path = match[1] === undefined ? parent : `${parent}/${match[1]}`.replace(/\/+/g, '/')
    found.push(path)
    if (!line.endsWith('/>')) open.push(path)
  }
  return [...new Set(found)].filter((p) => (p.startsWith('/app') || p.startsWith('/platform')) && !p.endsWith('*'))
}

describe('breadcrumb metadata', () => {
  it('covers every portal route in App.tsx and nothing else', () => {
    const routes = appRoutes(appSource)
    expect(routes.length).toBeGreaterThan(60) // the parser found the nested routes, not just the portals
    expect(routes.filter((r) => !BREADCRUMB_PATTERNS.includes(r))).toEqual([])
    expect(BREADCRUMB_PATTERNS.filter((p) => !routes.includes(p))).toEqual([])
  })

  it('gives every page a trail that starts at its portal home and ends on itself', () => {
    for (const pattern of BREADCRUMB_PATTERNS) {
      const path = pattern.replace(/:id/g, 'x-1')
      const trail = buildTrail(path)
      expect(trail.length, path).toBeGreaterThan(0)
      expect(trail[trail.length - 1].to, path).toBeUndefined()
      expect(trail[trail.length - 1].label, path).not.toBe('')
      if (path !== '/app' && path !== '/platform') expect(trail[0], path).toEqual({ label: 'Beranda', to: path.startsWith('/app') ? '/app' : '/platform' })
    }
  })
})

describe('buildTrail', () => {
  it('shows only the home page as the current page on the portal home', () => {
    expect(buildTrail('/app')).toEqual([{ label: 'Beranda' }])
    expect(buildTrail('/platform')).toEqual([{ label: 'Beranda' }])
  })

  it('puts the sidebar group between the home and the page, linked only when the group has a page of its own', () => {
    expect(buildTrail('/app/akuntansi')).toEqual([{ label: 'Beranda', to: '/app' }, { label: 'Akuntansi' }])
    expect(buildTrail('/app/akuntansi/jurnal')).toEqual([{ label: 'Beranda', to: '/app' }, { label: 'Akuntansi', to: '/app/akuntansi' }, { label: 'Jurnal' }])
    expect(buildTrail('/app/akuntansi/faktur-pelanggan')).toEqual([{ label: 'Beranda', to: '/app' }, { label: 'Piutang' }, { label: 'Faktur pelanggan' }])
    expect(buildTrail('/platform/tenants')).toEqual([{ label: 'Beranda', to: '/platform' }, { label: 'Pelanggan' }, { label: 'Tenant' }])
  })

  it('reads a detail page as the title the page announced, and falls back to "Detail"', () => {
    const detail = '/app/akuntansi/faktur-pelanggan/ai-1'
    expect(buildTrail(detail).map((c) => c.label)).toEqual(['Beranda', 'Piutang', 'Faktur pelanggan', 'Detail'])
    expect(buildTrail(detail, { [detail]: 'ARI-000001' })).toEqual([
      { label: 'Beranda', to: '/app' },
      { label: 'Piutang' },
      { label: 'Faktur pelanggan', to: '/app/akuntansi/faktur-pelanggan' },
      { label: 'ARI-000001' },
    ])
  })

  it('keeps the document in the trail of its editor, and tells create apart from detail', () => {
    const detail = '/app/akuntansi/jurnal/j-1'
    expect(buildTrail(`${detail}/ubah`, { [detail]: 'JV-0001' }).slice(2)).toEqual([{ label: 'Jurnal', to: '/app/akuntansi/jurnal' }, { label: 'JV-0001', to: detail }, { label: 'Ubah' }])
    expect(buildTrail('/app/akuntansi/jurnal/baru').slice(2)).toEqual([{ label: 'Jurnal', to: '/app/akuntansi/jurnal' }, { label: 'Baru' }])
  })

  it('does not mistake an unknown address for a page, and ignores pages outside the portals', () => {
    expect(buildTrail('/app/tidak-ada')).toEqual([{ label: 'Beranda', to: '/app' }, { label: 'Halaman tidak ditemukan' }])
    expect(buildTrail('/login')).toEqual([])
  })

  it('tolerates a trailing slash', () => {
    expect(buildTrail('/app/akuntansi/jurnal/').map((c) => c.label)).toEqual(['Beranda', 'Akuntansi', 'Jurnal'])
  })
})

describe('parentOf', () => {
  it('is the nearest ancestor that is a page, skipping a group that is only a label', () => {
    expect(parentOf(buildTrail('/app/akuntansi/faktur-pelanggan'))).toBe('/app')
    expect(parentOf(buildTrail('/app/akuntansi/faktur-pelanggan/ai-1'))).toBe('/app/akuntansi/faktur-pelanggan')
    expect(parentOf(buildTrail('/app/akuntansi/jurnal'))).toBe('/app/akuntansi')
    expect(parentOf(buildTrail('/app/akuntansi/jurnal/j-1/ubah'))).toBe('/app/akuntansi/jurnal/j-1')
  })

  it('is nothing on the portal home', () => {
    expect(parentOf(buildTrail('/app'))).toBeNull()
  })
})
