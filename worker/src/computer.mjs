/** Minimal CDP inspector for a browser you control locally. Read-only. */
export function allowedOrigin(url, origins = []) {
  try {
    const origin = new URL(url).origin
    return origins.some((item) => item && origin === new URL(item).origin)
  } catch { return false }
}

export function expressionFor(action = 'audit') {
  if (action === 'text') return 'document.body.innerText.slice(0, 5000)'
  if (action === 'links') return 'JSON.stringify([...document.querySelectorAll("a[href]")].slice(0,100).map(a=>a.href))'
  return `JSON.stringify({
    title: document.title,
    description: document.querySelector('meta[name="description"]')?.content || '',
    canonical: document.querySelector('link[rel="canonical"]')?.href || '',
    h1: document.querySelector('h1')?.innerText || '',
    jsonLd: document.querySelectorAll('script[type="application/ld+json"]').length,
    url: location.href
  })`
}

export async function cdpSend(socket, method, params = {}) {
  const id = Math.random().toString(36).slice(2)
  const pending = new Map()
  const handler = (event) => {
    const message = JSON.parse(event.data || '{}')
    if (message.id === id && pending.has(id)) { pending.get(id)(message); pending.delete(id) }
  }
  socket.addEventListener('message', handler)
  return new Promise((resolve, reject) => {
    pending.set(id, (message) => {
      socket.removeEventListener('message', handler)
      if (message.error) reject(new Error(message.error.message))
      else resolve(message.result)
    })
    socket.send(JSON.stringify({ id, method, params }))
    setTimeout(() => { if (pending.has(id)) { pending.delete(id); socket.removeEventListener('message', handler); reject(new Error('CDP timeout')) } }, 10000)
  })
}

export async function inspectPage({ url, origins = [], action = 'audit', debugPort = 9222 } = {}) {
  if (!allowedOrigin(url, origins)) throw new Error('Origin is not allowlisted')
  const targets = await (await fetch(`http://127.0.0.1:${debugPort}/json/list`)).json()
  const target = targets.find((item) => item.type === 'page' && item.url.startsWith(new URL(url).origin))
  if (!target) throw new Error('No matching browser tab open')
  const socket = new WebSocket(target.webSocketDebuggerUrl)
  await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject })
  try {
    const result = await cdpSend(socket, 'Runtime.evaluate', { expression: expressionFor(action), returnByValue: true })
    return result.result?.value ?? null
  } finally {
    socket.close()
  }
}
