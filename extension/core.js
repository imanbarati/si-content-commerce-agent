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
