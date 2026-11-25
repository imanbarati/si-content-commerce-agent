export const DEFAULTS = {
  feeds: [],
  xHandles: [],
  aiEndpoint: '',
  aiKey: '',
  researchProvider: 'google_news',
  googleCseKey: '',
  googleCseCx: '',
  deepseekEndpoint: 'https://api.deepseek.com/v1/search',
  deepseekKey: '',
  mcpDigikalaUrl: '',
  mcpTorobUrl: '',
  mcpTool: 'search_product',
  merchantMode: 'off',
  merchantToUrl: '',
  merchantToken: '',
  undercut: 1000,
  margin: 5,
  products: [],
  feedStatus: 'draft',
}

export function configFrom(env = {}) {
  const parseList = (value) => String(value || '').split(/[\n,]/).map((item) => item.trim()).filter(Boolean)
  const products = String(env.PRODUCTS || '').split('\n').map((line) => {
    const [sku, name, query, cost] = line.split('|').map((part) => (part || '').trim())
    return sku ? { sku, name, query: query || name, cost: Number(cost || 0) } : null
  }).filter(Boolean)
  return {
    ...DEFAULTS,
    feeds: parseList(env.FEEDS),
    xHandles: parseList(env.X_HANDLES),
    aiEndpoint: env.AI_ENDPOINT || DEFAULTS.aiEndpoint,
    aiKey: env.AI_KEY || DEFAULTS.aiKey,
    researchProvider: env.RESEARCH_PROVIDER || DEFAULTS.researchProvider,
    googleCseKey: env.GOOGLE_CSE_KEY || '',
    googleCseCx: env.GOOGLE_CSE_CX || '',
    deepseekEndpoint: env.DEEPSEEK_ENDPOINT || DEFAULTS.deepseekEndpoint,
    deepseekKey: env.DEEPSEEK_KEY || '',
    mcpDigikalaUrl: env.MCP_DIGIKALA_URL || '',
    mcpTorobUrl: env.MCP_TOROB_URL || '',
    merchantMode: env.MERCHANT_MODE || 'off',
    merchantToUrl: env.MERCHANT_TO_URL || '',
    merchantToken: env.MERCHANT_TOKEN || '',
    undercut: Number(env.UNDERCUT || DEFAULTS.undercut),
    margin: Number(env.MARGIN || DEFAULTS.margin),
    products: products.length ? products : DEFAULTS.products,
  }
}
