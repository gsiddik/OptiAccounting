/**
 * `tenant_hint` on the address when another product's switcher sends a person here: which of their organisations to open. It grants
 * nothing (OptiNexus decides) and is passed on only when it looks like a tenant id or a tenant code, the same rule the API applies.
 */
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i
const CODE = /^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/

export function tenantHintOf(search: string): string | null {
  const value = new URLSearchParams(search).get('tenant_hint')?.trim() ?? ''
  return UUID.test(value) || CODE.test(value) ? value : null
}

/** The sign-in address that keeps a valid hint and nothing else of the query. */
export function loginPath(search: string): string {
  const hint = tenantHintOf(search)
  return hint ? `/login?tenant_hint=${encodeURIComponent(hint)}` : '/login'
}
