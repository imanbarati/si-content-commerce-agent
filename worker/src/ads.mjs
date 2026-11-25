/** Offline campaign-CSV analysis. No ad-platform access, no automation. */
export function parseCsv(text) {
  const rows = []
  let row = [], field = '', quoted = false
  const source = String(text || '')
  for (let i = 0; i < source.length; i++) {
    const char = source[i]
    if (quoted) {
      if (char === '"' && source[i + 1] === '"') { field += '"'; i++; continue }
      else if (char === '"') quoted = false
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

const number = (value) => Number(String(value || '').replace(/[,%$]/g, '')) || 0

export function analyzeCampaign(rows) {
  const campaigns = new Map()
  for (const row of rows) {
    const name = row.campaign || row.campaign_name || '(uncategorized)'
    const entry = campaigns.get(name) || { campaign: name, impressions: 0, clicks: 0, cost: 0, conversions: 0 }
    entry.impressions += number(row.impressions)
    entry.clicks += number(row.clicks)
    entry.cost += number(row.cost || row.spend)
    entry.conversions += number(row.conversions)
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
    if (item.cpa > 0 && item.cpa > item.cpc * 25) recommendations.push(`${item.campaign}: high CPA (${item.cpa}) — review audience and bids manually.`)
  }
  if (!recommendations.length) recommendations.push('No obvious issues in the supplied export. Review with a human before changing bids.')
  return { campaigns: summary, recommendations, note: 'Advisory only. No changes were made to any ad account.' }
}
