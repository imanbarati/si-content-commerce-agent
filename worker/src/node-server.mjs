import http from 'node:http'
import fs from 'node:fs/promises'
import path from 'node:path'
import { createHandler } from './app.mjs'
import { createMemoryStore } from './store.mjs'

const DATA_FILE = path.resolve(process.env.DATA_FILE || 'data/worker.json')
const PORT = Number(process.env.PORT || 8786)

let initial = {}
try {
  initial = JSON.parse(await fs.readFile(DATA_FILE, 'utf8'))
} catch {
  // first run
}

const store = createMemoryStore(initial)
const handle = createHandler(store, process.env)

async function save() {
  await fs.mkdir(path.dirname(DATA_FILE), { recursive: true })
  await fs.writeFile(DATA_FILE, JSON.stringify(store.snapshot(), null, 2), 'utf8')
}

http.createServer(async (req, res) => {
  try {
    const chunks = []
    for await (const chunk of req) chunks.push(chunk)
    const body = Buffer.concat(chunks).toString('utf8')
    const headers = {}
    for (const [key, value] of Object.entries(req.headers)) {
      if (typeof value === 'string') headers[key] = value
    }
    const request = new Request(`http://127.0.0.1:${PORT}${req.url}`, {
      method: req.method,
      headers,
      body: ['GET', 'HEAD'].includes(req.method) ? undefined : body,
    })
    const response = await handle(request)
    await save()
    res.writeHead(response.status, Object.fromEntries(response.headers))
    res.end(Buffer.from(await response.arrayBuffer()))
  } catch (error) {
    res.writeHead(500, { 'content-type': 'application/json' })
    res.end(JSON.stringify({ ok: false, message: error.message }))
  }
}).listen(PORT, '127.0.0.1', () => {
  console.log(`Posmana Agent worker API: http://127.0.0.1:${PORT}/api/health`)
  console.log(`State file: ${DATA_FILE}`)
})
