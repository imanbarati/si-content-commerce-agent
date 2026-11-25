import {
  choosePrice, cleanInvisible, ensureHost, extractPrices, factCheck, getData,
  getSettings, mcpPayloadText, parseFeed, planQueries, rankResults, saveData,
  stripHtml,
} from './core.js'

async function fetchText(url, options = {}) {
  const allowed = await ensureHost(url)
  if (!allowed) throw new Error('Host permission denied for ' + new URL(url).origin)
  const response = await fetch(url, { ...options, signal: AbortSignal.timeout(12000) })
  if (!response.ok) throw new Error('HTTP ' + response.status)
  return response.text()
}

async function mcpCall(endpoint, tool, args) {
  const allowed = await ensureHost(endpoint)
  if (!allowed) throw new Error('Host permission denied')
  const response = await fetch(endpoint, {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify({ jsonrpc: '2.0', id: crypto.randomUUID(), method: 'tools/call', params: { name: tool, arguments: args } }),
    signal: AbortSignal.timeout(12000),
  })
  if (!response.ok) throw new Error('MCP HTTP ' + response.status)
  return response.json()
}

async function researchSearch(query, settings) {
  if (settings.researchProvider === 'google_news') {
    const url = `https://news.google.com/rss/search?q=${encodeURIComponent(query)}&hl=fa&gl=IR&ceid=IR:fa`
    return parseFeed(await fetchText(url), 5)
  }
  if (settings.researchProvider === 'google_cse') {
    if (!settings.googleCseKey || !settings.googleCseCx) throw new Error('Google CSE key/CX not configured')
    const url = `https://www.googleapis.com/customsearch/v1?key=${encodeURIComponent(settings.googleCseKey)}&cx=${encodeURIComponent(settings.googleCseCx)}&q=${encodeURIComponent(query)}&num=5`
    const data = JSON.parse(await fetchText(url))
    return (data.items || []).map((item) => ({ title: stripHtml(item.title), url: item.link, description: stripHtml(item.snippet) }))
  }
  if (settings.researchProvider === 'deepseek') {
    if (!settings.deepseekKey) throw new Error('DeepSeek key not configured')
    const data = JSON.parse(await fetchText(settings.deepseekEndpoint, {
      method: 'POST',
      headers: { 'content-type': 'application/json', authorization: 'Bearer ' + settings.deepseekKey },
      body: JSON.stringify({ query, count: 10 }),
    }))
    const rows = data.results || data.data || []
    return rows.map((item) => ({ title: stripHtml(item.title), url: item.url || item.link, description: stripHtml(item.snippet || item.content) }))
  }
  if (settings.researchProvider === 'mcp') {
    const endpoint = settings.mcpDigikalaUrl || settings.mcpTorobUrl
    if (!endpoint) throw new Error('MCP endpoint not configured')
    const data = await mcpCall(endpoint, 'search', { query })
    return [{ title: query, url: endpoint, description: mcpPayloadText(data) }]
  }
  throw new Error('Unknown research provider')
}

async function syncFeeds(settings, data) {
  let created = 0
  for (const url of settings.feeds) {
    try {
      for (const item of parseFeed(await fetchText(url), 5)) {
        if (data.posts.some((post) => post.guid === item.guid)) continue
        data.posts.unshift({ ...item, type: 'rss', status: settings.feedStatus, date: new Date().toISOString() })
        created++
      }
    } catch (error) {
      data.log.unshift({ time: new Date().toISOString(), message: 'Feed failed: ' + error.message })
    }
  }
  data.log.unshift({ time: new Date().toISOString(), message: `Feed sync: ${created} new post(s).` })
  data.log = data.log.slice(0, 100)
  return created
}

async function research(sku, settings, data) {
  const product = settings.products.find((item) => item.sku === sku)
  if (!product) throw new Error('Product not found')
  const items = []
  for (const query of planQueries(product.name)) {
    try { items.push(...(await researchSearch(query, settings))) } catch (error) { data.log.unshift({ time: new Date().toISOString(), message: error.message }) }
  }
  const findings = []
  for (const item of rankResults(items).slice(0, 6)) {
    try {
      const body = stripHtml(await fetchText(item.url))
      const prices = extractPrices(body)
      if (!prices.length) continue
      if (!factCheck(prices[0], body)) continue
      findings.push({ price: prices[0], source: item.url, title: item.title, confidence: item.score >= 3 ? 'high' : 'single-source' })
    } catch { /* skip unreachable source */ }
  }
  data.reports[sku] = { sku, product: product.name, findings, time: new Date().toISOString() }
  data.log.unshift({ time: new Date().toISOString(), message: `Research ${sku}: ${findings.length} verified finding(s).` })
  return data.reports[sku]
}

async function syncPrices(settings, data) {
  const suggestions = {}
  for (const product of settings.products) {
    const candidates = []
    for (const endpoint of [settings.mcpDigikalaUrl, settings.mcpTorobUrl].filter(Boolean)) {
      try {
        const payload = await mcpCall(endpoint, settings.mcpTool, { query: product.query || product.name })
        candidates.push(...extractPrices(mcpPayloadText(payload)))
      } catch (error) {
        data.log.unshift({ time: new Date().toISOString(), message: 'MCP failed: ' + error.message })
      }
    }
    if (!candidates.length) continue
    suggestions[product.sku] = {
      name: product.name,
      market: Math.min(...candidates),
      price: choosePrice(candidates, { undercut: settings.undercut, cost: product.cost, margin: settings.margin }),
    }
  }
  data.suggestions = suggestions
  if (['to', 'both'].includes(settings.merchantMode) && settings.merchantToUrl) {
    try {
      await ensureHost(settings.merchantToUrl)
      await fetch(settings.merchantToUrl, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'x-merchant-token': settings.merchantToken },
        body: JSON.stringify({ type: 'price_suggestions', data: suggestions }),
      })
    } catch (error) {
      data.log.unshift({ time: new Date().toISOString(), message: 'Merchant outbound failed: ' + error.message })
    }
  }
  data.log.unshift({ time: new Date().toISOString(), message: `Price sync: ${Object.keys(suggestions).length} suggestion(s).` })
  return suggestions
}

chrome.runtime.onInstalled.addListener(() => {
  chrome.alarms.create('posmana-agent-sync', { periodInMinutes: 60 })
})

chrome.alarms.onAlarm.addListener(async (alarm) => {
  if (alarm.name !== 'posmana-agent-sync') return
  const settings = await getSettings()
  if (!settings.feeds.length) return
  const data = await getData()
  await syncFeeds(settings, data)
  await saveData(data)
})

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  ;(async () => {
    const settings = await getSettings()
    const data = await getData()
    let result
    switch (message.type) {
      case 'status':
        result = {
          posts: data.posts.length,
          reports: Object.keys(data.reports).length,
          suggestions: Object.keys(data.suggestions).length,
          researchProvider: settings.researchProvider,
          merchantMode: settings.merchantMode,
          log: data.log.slice(0, 5),
        }
        break
      case 'syncFeeds': result = { created: await syncFeeds(settings, data) }; break
      case 'research': result = await research(message.sku, settings, data); break
      case 'syncPrices': result = { suggestions: await syncPrices(settings, data) }; break
      case 'cleanText': result = { text: cleanInvisible(message.text) }; break
      default: result = { error: 'Unknown action' }
    }
    await saveData(data)
    sendResponse({ ok: true, result })
  })().catch((error) => sendResponse({ ok: false, error: error.message }))
  return true
})
