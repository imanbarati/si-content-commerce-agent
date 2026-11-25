/** Self-contained extension core. Mirrors worker/src/core.mjs. No dependencies. */

export const DEFAULT_SETTINGS = {
  feeds: [],
  products: [],
  researchProvider: 'google_news',
  googleCseKey: '',
  googleCseCx: '',
  deepseekEndpoint: 'https://api.deepseek.com/v1/search',
  deepseekKey: '',
  mcpDigikalaUrl: '',
  mcpTorobUrl: '',
  mcpTool: 'search_product',
  aiEndpoint: '',
  aiKey: '',
  aiModel: 'gpt-4o-mini',
  merchantMode: 'off',
  merchantToUrl: '',
  merchantToken: '',
  undercut: 1000,
  margin: 5,
  feedStatus: 'draft',
}

export const EMPTY_DATA = { posts: [], suggestions: {}, reports: {}, log: [] }

export function faDigits(value) {
  const map = { '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4', '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9' }
  return String(value || '').replace(/[۰-۹]/g, (d) => map[d] || d)
}

export function stripHtml(value) {
  return String(value || '')
    .replace(/<script[\s\S]*?<\/script>/gi, ' ')
    .replace(/<style[\s\S]*?<\/style>/gi, ' ')
    .replace(/<[^>]+>/g, ' ')
    .replace(/&nbsp;/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&quot;/g, '"')
    .replace(/\s+/g, ' ')
    .trim()
}

export function planQueries(name) {
  const clean = stripHtml(name).trim()
  if (!clean) return []
  return [...new Set([`${clean} price`, `${clean} قیمت`, `${clean} price comparison`, `${clean} digikala torob`])]
}

export function extractPrices(text) {
  const normalized = faDigits(text)
  const prices = []
  const regex = /(?:price|قیمت|تومان|ریال)\s*:?\s*([0-9][0-9,\.]{3,})/giu
  let match
  while ((match = regex.exec(normalized))) {
    const number = Number(match[1].replace(/[,.]/g, ''))
    if (number > 1000) prices.push(number)
  }
  return [...new Set(prices)]
}

export function domainScore(url) {
  try {
    const host = new URL(url).hostname
    if (host.includes('digikala') || host.includes('torob')) return 3
    if (host.includes('news.google')) return 1
    return 2
  } catch { return 0 }
}

export function rankResults(items) {
  return [...items].filter((item) => item && item.url)
    .map((item) => ({ ...item, score: domainScore(item.url) }))
    .sort((a, b) => b.score - a.score)
}

export function factCheck(price, text) {
  return faDigits(text).replace(/[^0-9]/g, '').includes(String(Math.round(price)))
}

export function choosePrice(candidates, { undercut = 0, cost = 0, margin = 0 } = {}) {
  if (!candidates.length) return null
  const floor = Number(cost) * (1 + Number(margin) / 100)
  return Math.round(Math.max(Math.min(...candidates) - Number(undercut), floor))
}

export function parseFeed(xml, limit = 5) {
  const blocks = String(xml || '').match(/<item[\s\S]*?<\/item>|<entry[\s\S]*?<\/entry>/gi) || []
  const pick = (block, tag) => {
    const match = block.match(new RegExp(`<${tag}(?:\\s[^>]*)?>([\\s\\S]*?)<\\/${tag}>`, 'i'))
    if (!match) return ''
    return stripHtml(match[1].replace(/^\s*<!\[CDATA\[|\]\]>\s*$/g, ''))
  }
  return blocks.slice(0, limit).map((block) => ({
    guid: pick(block, 'guid') || pick(block, 'id') || pick(block, 'link'),
    title: pick(block, 'title'),
    url: pick(block, 'link').replace(/&amp;/g, '&'),
    description: pick(block, 'description') || pick(block, 'summary') || pick(block, 'content'),
  })).filter((item) => item.title && item.url)
}

export function cleanInvisible(text) {
  return String(text || '')
    .replace(/[\u200B-\u200D\uFEFF\u2060\u180E\u00AD\u202A-\u202E\u2066-\u2069\u200E\u200F]/gu, '')
    .replace(/[\u{E0000}-\u{E007F}]/gu, '')
    .replace(/[\u00A0\u2000-\u200A\u202F\u205F\u3000]/gu, ' ')
    .replace(/[ \t]{2,}/g, ' ')
}

export function mcpPayloadText(data) {
  const text = data?.result?.content?.[0]?.text
  return typeof text === 'string' ? text : JSON.stringify(data || {})
}

export function merchantTokenValid(provided, expected) {
  return Boolean(expected) && String(provided || '') === String(expected)
}

export function seoChecklist({ drafts = true, canonicalLinks = true, dryRun = true, sourcesCited = true } = {}) {
  return [
    { label: 'Reviewed before publishing', ok: drafts },
    { label: 'Canonical source link included', ok: canonicalLinks },
    { label: 'Prices remain suggestions', ok: dryRun },
    { label: 'Research claims cite sources', ok: sourcesCited },
  ]
}

export function parseLines(value) {
  return String(value || '').split(/\r?\n/).map((line) => line.trim()).filter(Boolean)
}

export function parseProducts(value) {
  return parseLines(value).map((line) => {
    const [sku, name, query, cost] = line.split('|').map((part) => (part || '').trim())
    return sku ? { sku, name, query: query || name, cost: Number(cost || 0) } : null
  }).filter(Boolean)
}

export async function getSettings() {
  const stored = await chrome.storage.local.get('settings')
  return { ...DEFAULT_SETTINGS, ...(stored.settings || {}) }
}

export async function saveSettings(settings) {
  await chrome.storage.local.set({ settings })
}

export async function getData() {
  const stored = await chrome.storage.local.get('data')
  return { ...EMPTY_DATA, ...(stored.data || {}) }
}

export async function saveData(data) {
  await chrome.storage.local.set({ data })
}

export async function ensureHost(url) {
  if (typeof chrome === 'undefined' || !chrome.permissions) return true
  const origin = new URL(url).origin
  const wanted = { origins: [origin] }
  if (await chrome.permissions.contains(wanted)) return true
  return chrome.permissions.request(wanted)
}

export function keywordVariants(seed) {
  const clean = String(seed || '').trim()
  if (!clean) return []
  const patterns = ['%s price', '%s price', 'buy %s', 'خرید %s', '%s review', 'بررسی %s', '%s comparison', 'مقایسه %s', '%s alternatives']
  return [...new Set(patterns.map((pattern) => pattern.replace('%s', clean)))]
}

export function affiliateLink(platform, url, tag = '') {
  const allowed = ['shopify', 'woocommerce', 'aliexpress', 'cj', 'spocket', 'zendrop', 'printify']
  if (!allowed.includes(platform)) return ''
  try {
    const parsed = new URL(url)
    if (!['http:', 'https:'].includes(parsed.protocol)) return ''
    parsed.searchParams.set('utm_source', 'si-content-agent')
    parsed.searchParams.set('utm_medium', 'affiliate')
    parsed.searchParams.set('utm_campaign', platform)
    if (tag) parsed.searchParams.set(platform === 'aliexpress' ? 'aff_id' : platform === 'cj' ? 'cid' : 'ref', tag)
    return parsed.toString()
  } catch { return '' }
}

export function campaignChecklist() {
  return [
    { label: 'No bot swarms or fake traffic', ok: true },
    { label: 'Official APIs only for ads automation', ok: true },
    { label: 'Affiliate links are disclosed', ok: true },
  ]
}

export function robotsAllows(robots, url, agent = 'SI-Agent') {
  try {
    const path = new URL(url).pathname || '/'
    let active = false, matched = false
    for (const raw of String(robots || '').split(/\r?\n/)) {
      const line = raw.replace(/#.*$/, '').trim()
      if (!line) continue
      const [key, ...rest] = line.split(':')
      const value = rest.join(':').trim()
      const directive = key.trim().toLowerCase()
      if (directive === 'user-agent') { active = value === '*' || agent.toLowerCase().includes(value.toLowerCase()); continue }
      if (!active) continue
      if (directive === 'disallow' && value && path.startsWith(value)) matched = true
      if (directive === 'allow' && value && path.startsWith(value)) matched = false
    }
    return !matched
  } catch { return false }
}

export function auditHtml(html, url) {
  const text = String(html || '')
  return { url, checks: [
    { label: 'title', ok: /<title[^>]*>[^<]{5,}<\/title>/i.test(text) },
    { label: 'meta description', ok: /<meta[^>]+name=["']description["'][^>]+content=["'][^"']{20,}/i.test(text) },
    { label: 'canonical', ok: /rel=["']canonical["']/i.test(text) },
    { label: 'json-ld', ok: text.toLowerCase().includes('application/ld+json') },
    { label: 'h1', ok: /<h1[\s>]/i.test(text) },
  ] }
}

export function parseCsv(text) {
  const rows = []
  let row = [], field = '', quoted = false
  const source = String(text || '')
  for (let i = 0; i < source.length; i++) {
    const char = source[i]
    if (quoted) {
      if (char === '"' && source[i + 1] === '"') { field += '"'; i++; continue }
      if (char === '"') quoted = false
      else field += char
      continue
    }
    if (char === '"') quoted = true
    else if (char === ',') { row.push(field); field = '' }
    else if (char === '\n') { row.push(field); if (row.some((cell) => cell.trim())) rows.push(row); row = []; field = '' }
    else if (char !== '\r') field += char
  }
  if (field || row.length) { row.push(field); if (row.some((cell) => cell.trim())) rows.push(row) }
  if (rows.length < 2) return []
  const headers = rows[0].map((value) => value.trim().toLowerCase().replace(/\s+/g, '_'))
  return rows.slice(1).map((cells) => Object.fromEntries(headers.map((header, index) => [header, (cells[index] || '').trim()])))
}

export function analyzeCampaign(rows) {
  const campaigns = new Map()
  for (const row of rows) {
    const name = row.campaign || row.campaign_name || '(uncategorized)'
    const entry = campaigns.get(name) || { campaign: name, impressions: 0, clicks: 0, cost: 0, conversions: 0 }
    entry.impressions += Number(String(row.impressions || '').replace(/[,%$]/g, '')) || 0
    entry.clicks += Number(String(row.clicks || '').replace(/[,%$]/g, '')) || 0
    entry.cost += Number(String(row.cost || row.spend || '').replace(/[,%$]/g, '')) || 0
    entry.conversions += Number(String(row.conversions || '').replace(/[,%$]/g, '')) || 0
    campaigns.set(name, entry)
  }
  const summary = [...campaigns.values()].map((item) => ({
    ...item,
    ctr: item.impressions ? +(item.clicks / item.impressions * 100).toFixed(2) : 0,
    cpc: item.clicks ? +(item.cost / item.clicks).toFixed(2) : 0,
    cpa: item.conversions ? +(item.cost / item.conversions).toFixed(2) : 0,
    conversionRate: item.clicks ? +(item.conversions / item.clicks * 100).toFixed(2) : 0,
  }))
  const recommendations = []
  for (const item of summary) {
    if (item.impressions > 100 && item.ctr < 1) recommendations.push(`${item.campaign}: low CTR (${item.ctr}%) — test creatives and targeting.`)
    if (item.clicks > 50 && item.conversionRate < 1) recommendations.push(`${item.campaign}: low conversion rate (${item.conversionRate}%) — check landing page relevance.`)
  }
  if (!recommendations.length) recommendations.push('No obvious issues in the supplied export. Review with a human before changing bids.')
  return { campaigns: summary, recommendations, note: 'Advisory only. No changes were made to any ad account.' }
}
