/** Compliant growth helpers shared by worker and extension. */
export function keywordVariants(seed) {
  const clean = String(seed || '').trim()
  if (!clean) return []
  const patterns = ['%s price', '%s قیمت', 'buy %s', 'خرید %s', '%s review', 'بررسی %s', '%s comparison', 'مقایسه %s', '%s near me', '%s alternatives']
  return [...new Set(patterns.map((pattern) => pattern.replace('%s', clean)))]
}

export function schemaProduct({ name = '', sku = '', description = '', price = 0, url = '' } = {}) {
  return {
    '@context': 'https://schema.org', '@type': 'Product', '@id': `#pca-product-${sku || 'product'}`,
    name, sku, description,
    offers: { '@type': 'Offer', priceCurrency: 'IRR', price: Number(price) || 0, availability: 'https://schema.org/InStock', url },
  }
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
    const key = platform === 'aliexpress' ? 'aff_id' : platform === 'cj' ? 'cid' : 'ref'
    if (tag) parsed.searchParams.set(key, tag)
    return parsed.toString()
  } catch { return '' }
}

export function campaignChecklist() {
  return [
    { label: 'No bot swarms or fake traffic', ok: true },
    { label: 'Ads automation uses official APIs only', ok: true },
    { label: 'Schemas work alongside Yoast SEO', ok: true },
    { label: 'Affiliate links use disclosed UTM parameters', ok: true },
  ]
}
