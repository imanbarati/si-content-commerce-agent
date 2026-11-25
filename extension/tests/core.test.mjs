import test from 'node:test'
import assert from 'node:assert/strict'
import {
  choosePrice, cleanInvisible, extractPrices, factCheck, faDigits, mcpPayloadText,
  affiliateLink, campaignChecklist, keywordVariants, merchantTokenValid, parseFeed, parseProducts, planQueries, rankResults, seoChecklist,
} from '../core.js'

const FEED = `<?xml version="1.0"?><rss><channel>
<item><title>Test &amp; Item</title><link>https://example.com/1</link><guid>abc-1</guid><description><![CDATA[<p>Hello</p>]]></description></item>
<item><title>Second</title><link>https://example.com/2</link><guid>abc-2</guid><description>World</description></item>
</channel></rss>`

test('digits, prices and query planning', () => {
  assert.equal(faDigits('۱۲۳۴'), '1234')
  assert.deepEqual(extractPrices('قیمت: ۱۲,۹۰۰,۰۰۰ تومان'), [12900000])
  assert.equal(planQueries({ name: 'پوزمانا مینی' }).length, 4)
})
test('ranking and fact checking', () => {
  assert.equal(rankResults([{ url: 'https://news.google.com/rss' }, { url: 'https://torob.com/p/1' }])[0].score, 3)
  assert.equal(factCheck(12900000, 'قیمت 12,900,000 تومان'), true)
  assert.equal(factCheck(12900000, 'nothing'), false)
})
test('price floor', () => assert.equal(choosePrice([10000000], { undercut: 1000, cost: 9500000, margin: 10 }), 10450000))
test('feed parsing', () => {
  const items = parseFeed(FEED, 5)
  assert.equal(items.length, 2)
  assert.equal(items[0].title, 'Test & Item')
})
test('privacy hygiene', () => {
  assert.equal(cleanInvisible('A\u200B\u202EB'), 'AB')
  assert.equal(cleanInvisible('a\u00A0\u00A0b'), 'a b')
})
test('MCP payload and products', () => {
  assert.equal(mcpPayloadText({ result: { content: [{ text: '{"price":5}' }] } }), '{"price":5}')
  assert.equal(parseProducts('mini|Mini|mini price|1000')[0].sku, 'mini')
})
test('token and checklist', () => {
  assert.equal(merchantTokenValid('a', 'a'), true)
  assert.ok(seoChecklist({ drafts: true, canonicalLinks: true, dryRun: true, sourcesCited: true }).every((i) => i.ok))
})

test('growth helpers', () => {
  assert.equal(keywordVariants('mini').length, 8)
  assert.ok(affiliateLink('shopify', 'https://x.example/p', 't').includes('utm_medium=affiliate'))
  assert.ok(campaignChecklist().some((i) => i.label.includes('No bot swarms')))
})
