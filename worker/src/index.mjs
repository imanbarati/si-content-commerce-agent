import { createHandler } from './app.mjs'
import { createMemoryStore } from './store.mjs'

/**
 * Cloudflare Worker entrypoint. State is per-isolate memory; for persistent
 * state add a KV binding later. No database is required for the core API.
 */
const store = createMemoryStore()

export default {
  async fetch(request, env) {
    try {
      return await createHandler(store, env)(request)
    } catch (error) {
      return new Response(JSON.stringify({ ok: false, message: error.message }), {
        status: 500, headers: { 'content-type': 'application/json' },
      })
    }
  },
}
