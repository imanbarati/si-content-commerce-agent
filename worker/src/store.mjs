/** In-memory store. Cloudflare Workers use this; Node wraps it with file persistence. */
export function createMemoryStore(initial = {}) {
  const state = {
    posts: [], suggestions: {}, reports: {}, research: {}, log: [],
    ...initial,
  }
  return {
    get(key) { return state[key] },
    set(key, value) { state[key] = value },
    addPost(post) {
      if (state.posts.some((item) => item.guid === post.guid)) return false
      state.posts.unshift(post)
      return true
    },
    log(message) {
      state.log.unshift({ time: new Date().toISOString(), message: String(message).slice(0, 240) })
      state.log = state.log.slice(0, 100)
    },
    snapshot() { return JSON.parse(JSON.stringify(state)) },
  }
}
