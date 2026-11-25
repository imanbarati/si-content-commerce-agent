import { DEFAULT_SETTINGS, getSettings, saveSettings } from './core.js'

const fields = [
  'researchProvider', 'googleCseKey', 'googleCseCx', 'deepseekEndpoint', 'deepseekKey',
  'mcpDigikalaUrl', 'mcpTorobUrl', 'aiEndpoint', 'aiKey', 'undercut', 'margin',
  'merchantMode', 'merchantToUrl', 'merchantToken',
]

const lines = (value) => String(value || '').split(/\r?\n/).map((line) => line.trim()).filter(Boolean).join('\n')

async function load() {
  const settings = await getSettings()
  document.getElementById('feeds').value = (settings.feeds || []).join('\n')
  document.getElementById('products').value = (settings.products || []).map((p) => `${p.sku}|${p.name}|${p.query}|${p.cost}`).join('\n')
  for (const field of fields) {
    const el = document.getElementById(field)
    if (el) el.value = settings[field] ?? DEFAULT_SETTINGS[field] ?? ''
  }
}

document.getElementById('save').addEventListener('click', async () => {
  const settings = await getSettings()
  settings.feeds = lines(document.getElementById('feeds').value).split('\n').filter(Boolean)
  settings.products = lines(document.getElementById('products').value).split('\n').map((line) => {
    const [sku, name, query, cost] = line.split('|').map((part) => (part || '').trim())
    return sku ? { sku, name, query: query || name, cost: Number(cost || 0) } : null
  }).filter(Boolean)
  for (const field of fields) {
    const el = document.getElementById(field)
    if (!el) continue
    settings[field] = ['undercut', 'margin'].includes(field) ? Number(el.value) : el.value
  }
  await saveSettings(settings)
  const button = document.getElementById('save')
  button.textContent = 'Saved ✓'
  setTimeout(() => { button.textContent = 'Save settings' }, 1500)
})

load()
