// Shows how two stored envelopes of the same event differ (the stored original and a later delivery with other content). Display only: nothing here
// decides anything, the person reads the differences and decides.

export type Difference = { path: string; stored: string | undefined; received: string | undefined }

function flatten(value: unknown, path: string, out: Map<string, string>): void {
  if (Array.isArray(value)) {
    if (value.length === 0) out.set(path, '[]')
    value.forEach((item, index) => flatten(item, `${path}[${index}]`, out))
  } else if (value !== null && typeof value === 'object') {
    const entries = Object.entries(value as Record<string, unknown>)
    if (entries.length === 0) out.set(path, '{}')
    for (const [key, item] of entries) flatten(item, path === '' ? key : `${path}.${key}`, out)
  } else {
    out.set(path, value === null || value === undefined ? 'null' : String(value))
  }
}

/** The leaf values that differ between the two envelopes, in the order of the stored one first; a value present on one side only has `undefined` on the other. */
export function envelopeDifferences(stored: unknown, received: unknown): Difference[] {
  const a = new Map<string, string>()
  const b = new Map<string, string>()
  flatten(stored, '', a)
  flatten(received, '', b)
  const paths = [...new Set([...a.keys(), ...b.keys()])]
  return paths.filter((path) => a.get(path) !== b.get(path)).map((path) => ({ path, stored: a.get(path), received: b.get(path) }))
}
