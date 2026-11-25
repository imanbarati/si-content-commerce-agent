/**
 * Local test bridge for the price engine. NOT a real Digikala/Torob integration.
 * Run: MOCK_PRICE=12900000 node bridges/mock-mcp.mjs
 * Then set the MCP endpoint in the plugin to http://127.0.0.1:8789/
 */
import http from 'node:http'

const PORT = Number(process.env.PORT || 8789)
const PRICE = Number(process.env.MOCK_PRICE || 12900000)

http.createServer(async (req, res) => {
  let raw = ''
  for await (const chunk of req) raw += chunk
  let body
  try { body = JSON.parse(raw) } catch { body = {} }
  const name = body.params?.name || body.method || 'unknown'
  const query = body.params?.arguments?.query || ''
  const payload = JSON.stringify({ name, price: PRICE, query, source: 'mock' })
  res.writeHead(200, { 'content-type': 'application/json' })
  res.end(JSON.stringify({
    jsonrpc: '2.0',
    id: body.id ?? null,
    result: { content: [{ type: 'text', text: payload }] },
  }))
}).listen(PORT, '127.0.0.1', () => console.log(`mock MCP at http://127.0.0.1:${PORT}/ price=${PRICE}`))
