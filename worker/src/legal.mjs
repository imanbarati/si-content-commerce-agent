/** Legal, audit-only crawler for domains you own. No swarm, no writes. */
export function robotsAllows(robots, url, agent = 'SI-Agent') {
  try {
    const parsed = new URL(url)
    const path = parsed.pathname || '/'
    let active = false
    let matched = false
    for (const raw of String(robots || '').split(/\r?\n/)) {
      const line = raw.replace(/#.*$/, '').trim()
      if (!line) continue
      const [key, ...rest] = line.split(':')
      const value = rest.join(':').trim()
      const directive = key.trim().toLowerCase()
      if (directive === 'user-agent') {
        active = value === '*' || agent.toLowerCase().includes(value.toLowerCase())
        continue
      }
      if (!active) continue
      if (directive === 'disallow' && value && path.startsWith(value)) matched = true
      if (directive === 'allow' && value && path.startsWith(value)) matched = false
    }
    return !matched
  } catch { return false }
}

export function auditHtml(html, url) {
  const text = String(html || '')
  return {
    url,
    checks: [
      { label: 'title', ok: /<title[^>]*>[^<]{5,}<\/title>/i.test(text) },
      { label: 'meta description', ok: /<meta[^>]+name=["']description["'][^>]+content=["'][^"']{20,}/i.test(text) },
      { label: 'canonical', ok: /rel=["']canonical["']/i.test(text) },
      { label: 'json-ld', ok: /application\/ld\+json/i.test(text) },
      { label: 'h1', ok: /<h1[\s>]/i.test(text) },
    ],
  }
}

export async function legalAudit({ domains = [], paths = ['/'], agent = 'SI-Agent', delayMs = 250 } = {}) {
  const results = []
  for (const domain of domains.slice(0, 5)) {
    let base
    try { base = new URL(domain.startsWith('http') ? domain : `https://${domain}`) } catch { continue }
    let robots = ''
    try {
      const response = await fetch(new URL('/robots.txt', base), { signal: AbortSignal.timeout(8000) })
      robots = response.ok ? await response.text() : ''
    } catch { /* no robots file means no explicit disallow */ }
    for (const path of paths.slice(0, 10)) {
      const url = new URL(path, base).toString()
      if (!robotsAllows(robots, url, agent)) {
        results.push({ url, skipped: 'robots.txt' })
        continue
      }
      try {
        const response = await fetch(url, { signal: AbortSignal.timeout(8000), headers: { 'user-agent': agent } })
        results.push(auditHtml(response.ok ? await response.text() : '', url))
      } catch (error) {
        results.push({ url, error: error.message })
      }
      await new Promise((resolve) => setTimeout(resolve, delayMs))
    }
  }
  return results
}
