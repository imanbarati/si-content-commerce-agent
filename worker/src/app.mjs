import { configFrom } from './config.mjs'
import { cleanInvisible } from './privacy.mjs'
import { affiliateLink, campaignChecklist, keywordVariants } from './growth.mjs'
import {
  choosePrice, extractPrices, factCheck, faDigits, merchantTokenValid,
  parseFeed, planQueries, rankResults, stripHtml,
} from './core.mjs'

const json = (data, status = 200) => new Response(JSON.stringify(data), {
  status,
  headers: { 'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store' },
})
const fail = (message, status = 400) => json({ ok: false, message }, status)

function signal() {
  return typeof AbortSignal.timeout === 'function' ? AbortSignal.timeout(10000) : undefined
}

async function getJson(url, options = {}) {
  const response = await fetch(url, { ...options, signal: signal() })
  if (!response.ok) throw new Error(`HTTP ${response.status}`)
  return response.json()
}

async function mcpCall(endpoint, tool, args) {
  const response = await fetch(endpoint, {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify({ jsonrpc: '2.0', id: crypto.randomUUID(), method: 'tools/call', params: { name: tool, arguments: args } }),
    signal: signal(),
  })
  if (!response.ok) throw new Error(`MCP HTTP ${response.status}`)
  return response.json()
}

function payloadText(data) {
  const text = data?.result?.content?.[0]?.text
  return typeof text === 'string' ? text : JSON.stringify(data || {})
}

async function researchSearch(query, cfg) {
  if (cfg.researchProvider === 'google_news') {
    const url = `https://news.google.com/rss/search?q=${encodeURIComponent(query)}&hl=fa&gl=IR&ceid=IR:fa`
    const response = await fetch(url, { signal: signal() })
    if (!response.ok) throw new Error(`News RSS HTTP ${response.status}`)
    return parseFeed(await response.text(), 5)
  }
  if (cfg.researchProvider === 'google_cse') {
    if (!cfg.googleCseKey || !cfg.googleCseCx) throw new Error('Google CSE key/CX not configured')
    const url = `https://www.googleapis.com/customsearch/v1?key=${encodeURIComponent(cfg.googleCseKey)}&cx=${encodeURIComponent(cfg.googleCseCx)}&q=${encodeURIComponent(query)}&num=5`
    const data = await getJson(url)
    return (data.items || []).map((item) => ({ title: stripHtml(item.title), url: item.link, description: stripHtml(item.snippet) }))
  }
  if (cfg.researchProvider === 'deepseek') {
    if (!cfg.deepseekKey) throw new Error('DeepSeek key not configured')
    const data = await getJson(cfg.deepseekEndpoint, {
      method: 'POST',
      headers: { 'content-type': 'application/json', authorization: `Bearer ${cfg.deepseekKey}` },
      body: JSON.stringify({ query, count: 10 }),
    })
    const rows = data.results || data.data || []
    return rows.map((item) => ({ title: stripHtml(item.title), url: item.url || item.link, description: stripHtml(item.snippet || item.content) }))
  }
  if (cfg.researchProvider === 'mcp') {
    const endpoint = cfg.mcpDigikalaUrl || cfg.mcpTorobUrl
    if (!endpoint) throw new Error('MCP endpoint not configured')
    const data = await mcpCall(endpoint, 'search', { query })
    return [{ title: query, url: endpoint, description: payloadText(data) }]
  }
  throw new Error('Unknown research provider')
}

export function createHandler(store, env = {}) {
  const cfg = configFrom(env)

  async function syncFeeds() {
    let created = 0
    for (const url of cfg.feeds) {
      try {
        const response = await fetch(url, { signal: signal() })
        if (!response.ok) throw new Error(`HTTP ${response.status}`)
        for (const item of parseFeed(await response.text(), 5)) {
          if (store.addPost({ ...item, type: 'rss', status: cfg.feedStatus, date: new Date().toISOString() })) created++
        }
      } catch (error) {
        store.log(`Feed failed ${url}: ${error.message}`)
      }
    }
    store.log(`Feed sync finished: ${created} new post(s).`)
    return { ok: true, created }
  }

  async function research(sku) {
    const product = cfg.products.find((item) => item.sku === sku)
    if (!product) return fail('Product not found', 404)

    const items = []
    for (const query of planQueries(product.name)) {
      try {
        items.push(...(await researchSearch(query, cfg)))
      } catch (error) {
        store.log(`Research query failed: ${error.message}`)
      }
    }
    const ranked = rankResults(items).slice(0, 6)
    const findings = []
    for (const item of ranked) {
      try {
        const response = await fetch(item.url, { signal: signal() })
        if (!response.ok) continue
        const body = stripHtml(await response.text())
        const prices = extractPrices(body)
        if (!prices.length) continue
        const price = prices[0]
        if (!factCheck(price, faDigits(body))) continue
        findings.push({ price, source: item.url, title: item.title, confidence: item.score >= 3 ? 'high' : 'single-source' })
      } catch (error) {
        store.log(`Fetch failed ${item.url}: ${error.message}`)
      }
    }
    const report = { sku, product: product.name, queries: planQueries(product.name), findings, time: new Date().toISOString() }
    const reports = store.get('reports') || {}
    reports[sku] = report
    store.set('reports', reports)
    store.log(`Research finished for ${sku}: ${findings.length} verified finding(s).`)
    return report
  }

  async function syncPrices() {
    const suggestions = {}
    for (const product of cfg.products) {
      const candidates = []
      for (const endpoint of [cfg.mcpDigikalaUrl, cfg.mcpTorobUrl].filter(Boolean)) {
        try {
          const data = await mcpCall(endpoint, cfg.mcpTool, { query: product.query || product.name })
          for (const value of extractPrices(payloadText(data))) candidates.push(value)
          const direct = Number(data?.price || data?.result?.price)
          if (Number.isFinite(direct) && direct > 0) candidates.push(direct)
        } catch (error) {
          store.log(`Price MCP failed: ${error.message}`)
        }
      }
      if (!candidates.length) continue
      suggestions[product.sku] = {
        name: product.name,
        market: Math.min(...candidates),
        price: choosePrice(candidates, { undercut: cfg.undercut, cost: product.cost, margin: cfg.margin }),
      }
    }
    store.set('suggestions', suggestions)
    store.log(`Price sync: ${Object.keys(suggestions).length} suggestion(s).`)
    return { ok: true, suggestions }
  }

  return async function handle(request) {
    const url = new URL(request.url)
    const path = url.pathname
    const method = request.method

    if (path === '/api/health') return json({ ok: true, service: 'posmana-agent-worker', mode: 'worker' })
    if (path === '/api/status') {
      return json({
        ok: true,
        posts: (store.get('posts') || []).length,
        suggestions: Object.keys(store.get('suggestions') || {}).length,
        reports: Object.keys(store.get('reports') || {}).length,
        researchProvider: cfg.researchProvider,
        merchantMode: cfg.merchantMode,
        log: (store.get('log') || []).slice(0, 10),
      })
    }
    if (path === '/api/posts') return json(store.get('posts') || [])
    if (path === '/api/growth/keywords' && method === 'POST') {
      let body
      try { body = await request.json() } catch { return fail('Invalid JSON', 400) }
      return json({ ok: true, variants: keywordVariants(body.seed) })
    }
    if (path === '/api/growth/affiliate' && method === 'POST') {
      let body
      try { body = await request.json() } catch { return fail('Invalid JSON', 400) }
      const link = affiliateLink(body.platform, body.url, body.tag)
      return link ? json({ ok: true, url: link }) : fail('Invalid platform or URL', 400)
    }
    if (path === '/api/growth/campaign') return json({ ok: true, checklist: campaignChecklist() })
    if (path === '/api/privacy/clean' && method === 'POST') {
      let body
      try { body = await request.json() } catch { return fail('Invalid JSON', 400) }
      return json({ ok: true, text: cleanInvisible(body.text) })
    }
    if (path === '/api/config') {
      return json({ feeds: cfg.feeds.length, products: cfg.products.length, researchProvider: cfg.researchProvider, merchantMode: cfg.merchantMode })
    }
    if (path === '/api/feeds/sync' && method === 'POST') return json(await syncFeeds())
    if (path === '/api/prices/sync' && method === 'POST') return json(await syncPrices())
    if (path.startsWith('/api/research/') && method === 'POST') return json(await research(decodeURIComponent(path.split('/').pop())))

    if (path === '/api/merchant/inbound' && method === 'POST') {
      if (!['from', 'both'].includes(cfg.merchantMode)) return fail('Inbound merchant mode is off', 403)
      if (!merchantTokenValid(request.headers.get('x-merchant-token'), cfg.merchantToken)) return fail('Invalid merchant token', 401)
      let body
      try { body = await request.json() } catch { return fail('Invalid JSON', 400) }
      if (!body.title || !body.content) return fail('title and content required', 400)
      const post = {
        guid: `merchant-${crypto.randomUUID()}`,
        title: stripHtml(body.title),
        description: stripHtml(body.content),
        url: '',
        sku: String(body.sku || ''),
        type: 'merchant',
        status: 'draft',
        date: new Date().toISOString(),
      }
      const added = store.addPost(post)
      store.log(`Merchant inbound post ${added ? post.guid : 'duplicate'}`)
      return json({ ok: true, guid: post.guid, created: added })
    }

    return fail('Not found', 404)
  }
}
