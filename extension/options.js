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

document.getElementById('export').addEventListener('click', async () => {
  const settings = await getSettings()
  const blob = new Blob([JSON.stringify(settings, null, 2)], { type: 'application/json' })
  const link = document.createElement('a')
  link.href = URL.createObjectURL(blob)
  link.download = 'si-agent-settings.json'
  link.click()
  setTimeout(() => URL.revokeObjectURL(link.href), 1000)
})

document.getElementById('import').addEventListener('click', () => document.getElementById('import-file').click())
document.getElementById('import-file').addEventListener('change', async (event) => {
  const file = event.target.files?.[0]
  if (!file) return
  try {
    const incoming = JSON.parse(await file.text())
    const settings = await getSettings()
    await saveSettings({ ...settings, ...incoming })
    await load()
    const button = document.getElementById('save')
    button.textContent = 'Imported ✓'
    setTimeout(() => { button.textContent = 'Save settings' }, 1500)
  } catch (error) {
    alert('Import failed: ' + error.message)
  }
})
