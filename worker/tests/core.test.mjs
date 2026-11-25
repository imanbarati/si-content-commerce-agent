import test from 'node:test'
import assert from 'node:assert/strict'
import {
  choosePrice, extractPrices, factCheck, faDigits, merchantTokenValid,
  parseFeed, planQueries, rankResults, seoChecklist, stripHtml,
} from '../src/core.mjs'
import { cleanInvisible } from '../src/privacy.mjs'
import { affiliateLink, campaignChecklist, keywordVariants, schemaProduct } from '../src/growth.mjs'

const FEED = `<?xml version="1.0"?><rss><channel>
<item><title>Test &amp; Item</title><link>https://example.com/1</link><guid>abc-1</guid><description><![CDATA[<p>Hello</p>]]></description></item>
<item><title>Second</title><link>https://example.com/2</link><guid>abc-2</guid><description>World</description></item>
</channel></rss>`

test('Persian digits normalize', () => assert.equal(faDigits('۱۲۳۴'), '1234'))
test('stripHtml removes tags and decodes entities', () => assert.equal(stripHtml('<p>A &amp; B</p>'), 'A & B'))
test('query planner produces four variants', () => assert.equal(planQueries({ name: 'پوزمانا مینی' }).length, 4))
test('price extraction handles Persian separators', () => assert.deepEqual(extractPrices('قیمت: ۱۲,۹۰۰,۰۰۰ تومان'), [12900000]))
test('domain ranking prefers merchant domains', () => {
  const ranked = rankResults([{ url: 'https://news.google.com/rss' }, { url: 'https://www.digikala.com/p/1' }])
  assert.equal(ranked[0].score, 3)
})
test('fact check requires price digits in source', () => {
  assert.equal(factCheck(12900000, 'قیمت 12,900,000 تومان'), true)
  assert.equal(factCheck(12900000, 'nothing here'), false)
})
test('price floor respects cost and margin', () => {
  assert.equal(choosePrice([10000000], { undercut: 1000, cost: 9500000, margin: 10 }), 10450000)
})
test('feed parser reads items', () => {
  const items = parseFeed(FEED, 5)
  assert.equal(items.length, 2)
  assert.equal(items[0].title, 'Test & Item')
  assert.equal(items[0].guid, 'abc-1')
})
test('merchant token check', () => {
  assert.equal(merchantTokenValid('abc', 'abc'), true)
  assert.equal(merchantTokenValid('wrong', 'abc'), false)
})
test('SEO checklist is compliance oriented', () => {
  const list = seoChecklist({ drafts: true, canonicalLinks: true, dryRun: true, sourcesCited: true })
  assert.ok(list.every((item) => item.ok))
})

test('hidden character hygiene removes zero-width and bidi controls', () => {
  assert.equal(cleanInvisible('A\u200B\u202EB'), 'AB')
})
test('exotic spaces normalize', () => {
  assert.equal(cleanInvisible('a\u00A0\u00A0b'), 'a b')
})

test('keyword variants and schema', () => {
  assert.equal(keywordVariants('posmana mini').length, 10)
  assert.equal(schemaProduct({ name: 'Mini', sku: 'mini', price: 10 }).offers.price, 10)
})
test('affiliate links carry consent-friendly UTMs', () => {
  const link = affiliateLink('shopify', 'https://shop.example/p/1', 'partner')
  assert.ok(link.includes('utm_medium=affiliate'))
  assert.ok(link.includes('ref=partner'))
  assert.equal(affiliateLink('evil', 'https://shop.example'), '')
})
test('campaign checklist refuses swarm behaviour', () => {
  assert.ok(campaignChecklist().some((item) => item.label.includes('No bot swarms')))
})
