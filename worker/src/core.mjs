/** Pure logic shared by the Node server and the Cloudflare Worker. No dependencies. */

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
    .replace(/&#8211;/g, '–')
    .replace(/&#8217;/g, '’')
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
  } catch {
    return 0
  }
}

export function rankResults(items) {
  return [...items]
    .filter((item) => item && item.url)
    .map((item) => ({ ...item, score: domainScore(item.url) }))
    .sort((a, b) => b.score - a.score)
}

export function factCheck(price, text) {
  const digits = faDigits(text).replace(/[^0-9]/g, '')
  return digits.includes(String(Math.round(price)))
}

export function choosePrice(candidates, { undercut = 0, cost = 0, margin = 0 } = {}) {
  if (!candidates.length) return null
  const lowest = Math.min(...candidates)
  const floor = Number(cost) * (1 + Number(margin) / 100)
  return Math.round(Math.max(lowest - Number(undercut), floor))
}

/** Minimal RSS/Atom item parser for feed import (no XML dependency). */
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

export function merchantTokenValid(provided, expected) {
  if (!expected) return false
  return String(provided || '') === String(expected)
}

export function seoChecklist({ drafts = true, canonicalLinks = true, dryRun = true, sourcesCited = true } = {}) {
  return [
    { label: 'Imported content is reviewed before publishing', ok: drafts },
    { label: 'Canonical source link included', ok: canonicalLinks },
    { label: 'Price changes remain suggestions until reviewed', ok: dryRun },
    { label: 'Research claims cite their sources', ok: sourcesCited },
  ]
}
