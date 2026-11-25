import test from 'node:test'
import assert from 'node:assert/strict'
import { createHandler } from '../src/app.mjs'
import { createMemoryStore } from '../src/store.mjs'

const env = { FEEDS: '', PRODUCTS: 'mini|Posmana Mini|posmana mini price|1000000', MERCHANT_MODE: 'both', MERCHANT_TOKEN: 'secret-token' }

test('health and status endpoints', async () => {
  const handle = createHandler(createMemoryStore(), env)
  const health = await handle(new Request('http://localhost/api/health'))
  assert.equal(health.status, 200)
  assert.equal((await health.json()).service, 'posmana-agent-worker')
  const status = await handle(new Request('http://localhost/api/status'))
  assert.equal((await status.json()).researchProvider, 'google_news')
})

test('merchant inbound requires token and creates a draft', async () => {
  const handle = createHandler(createMemoryStore(), env)
  const denied = await handle(new Request('http://localhost/api/merchant/inbound', {
    method: 'POST', headers: { 'content-type': 'application/json', 'x-merchant-token': 'wrong' },
    body: JSON.stringify({ title: 'A', content: 'B' }),
  }))
  assert.equal(denied.status, 401)

  const ok = await handle(new Request('http://localhost/api/merchant/inbound', {
    method: 'POST', headers: { 'content-type': 'application/json', 'x-merchant-token': 'secret-token' },
    body: JSON.stringify({ title: 'A', content: 'B', sku: 'mini' }),
  }))
  assert.equal(ok.status, 200)
  const posts = await handle(new Request('http://localhost/api/posts'))
  assert.equal((await posts.json()).length, 1)
})

test('config endpoint does not leak secrets', async () => {
  const handle = createHandler(createMemoryStore(), env)
  const response = await handle(new Request('http://localhost/api/config'))
  const body = await response.json()
  assert.equal(JSON.stringify(body).includes('secret-token'), false)
})
