import { useState } from 'react'
import { api } from '../../../lib/api'
import { useDebounced, useResource } from '../../../lib/hooks'
import { listParams, type Page } from '../../../lib/operational'

type Value = string | boolean
type Params = Record<string, string | number | boolean>

/**
 * A server-side filtered, paginated list: the filter object and the page live here, the search text is debounced, a filter change returns
 * to page 1, and `exportParams` is the same filter without the page (what an export endpoint takes).
 */
export function usePagedList<T, F extends Record<string, Value>>(path: string, initial: F, serialize: (filter: F, page: number) => Params = (f, p) => listParams(f, p)) {
  const [filter, setFilter] = useState<F>(initial)
  const [page, setPage] = useState(1)
  const q = useDebounced(typeof filter.q === 'string' ? filter.q : '')
  const applied = { ...filter, ...(typeof filter.q === 'string' ? { q } : {}) } as F
  const key = JSON.stringify(applied)

  const list = useResource(async () => (await api.get<Page<T>>(path, { params: serialize(applied, page) })).data, [path, page, key])

  const change = <K extends keyof F>(name: K, value: F[K]) => {
    setFilter((s) => ({ ...s, [name]: value }))
    setPage(1)
  }
  const exportParams = { ...serialize(applied, 1) }
  delete exportParams.page
  return { filter, change, page, setPage, list, exportParams }
}
