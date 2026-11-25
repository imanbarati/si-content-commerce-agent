const out = document.getElementById('output')
const status = document.getElementById('status')

function show(value) {
  out.textContent = typeof value === 'string' ? value : JSON.stringify(value, null, 2)
}

async function send(message) {
  status.textContent = 'Working…'
  try {
    const response = await chrome.runtime.sendMessage(message)
    if (!response?.ok) throw new Error(response?.error || 'Unknown error')
    show(response.result)
    await refresh()
    return response.result
  } catch (error) {
    status.textContent = 'Error: ' + error.message
    show(error.message)
    return null
  }
}

async function refresh() {
  const response = await chrome.runtime.sendMessage({ type: 'status' })
  if (response?.ok) {
    const r = response.result
    status.textContent = `${r.posts} posts · ${r.reports} reports · ${r.suggestions} suggestions · ${r.researchProvider}`
  }
}

document.getElementById('sync-feeds').addEventListener('click', () => send({ type: 'syncFeeds' }))
document.getElementById('sync-prices').addEventListener('click', () => send({ type: 'syncPrices' }))
document.getElementById('research').addEventListener('click', () => {
  const sku = document.getElementById('sku').value.trim()
  if (sku) send({ type: 'research', sku })
})
document.getElementById('clean').addEventListener('click', () => {
  const text = document.getElementById('text').value
  send({ type: 'cleanText', text })
})
document.getElementById('options').addEventListener('click', () => chrome.runtime.openOptionsPage())

refresh()
