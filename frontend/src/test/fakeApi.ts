import { AxiosError } from 'axios'
import { api } from '../lib/api'

type Request = { method: string; url: string; data: unknown; params: Record<string, unknown> | undefined; headers: Record<string, unknown> }
type Reply = { status?: number; data?: unknown }
type Handler = Reply | ((request: Request) => Reply)

/**
 * Replace the HTTP transport with an in-memory table of "METHOD /path" → reply.
 * An unmocked request fails the test loudly instead of silently returning nothing.
 */
export function mockApi(routes: Record<string, Handler>) {
  const calls: Request[] = []
  api.defaults.adapter = async (config) => {
    const method = (config.method ?? 'get').toUpperCase()
    const url = config.url ?? ''
    const request: Request = { method, url, data: typeof config.data === 'string' ? JSON.parse(config.data) : config.data, params: config.params, headers: config.headers.toJSON() }
    calls.push(request)

    const route = routes[`${method} ${url}`]
    if (!route) throw new Error(`Unmocked request: ${method} ${url}`)

    const reply = typeof route === 'function' ? route(request) : route
    const status = reply.status ?? 200
    const response = { data: reply.data ?? {}, status, statusText: String(status), headers: {}, config }
    if (status >= 400) throw new AxiosError(`HTTP ${status}`, 'ERR_BAD_REQUEST', config, null, response)
    return response
  }
  return calls
}

export const page = <T>(data: T[]) => ({ data, current_page: 1, last_page: 1, total: data.length, per_page: 25 })
