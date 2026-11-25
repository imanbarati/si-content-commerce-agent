/** Bounded, read-only autonomy. Never publishes, bids or creates accounts. */
export function cyclePlan(env = {}) {
  return {
    enabled: String(env.AUTONOMY || '').toLowerCase() === 'on',
    tasks: ['feeds', 'prices', 'owned_domain_audit'],
    publish: false,
    bidChanges: false,
  }
}

export function recordCycle(store, result) {
  const history = store.get('autonomy') || []
  const entry = { ...result, time: new Date().toISOString() }
  store.set('autonomy', [entry, ...history].slice(0, 20))
  return entry
}
