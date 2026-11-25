# AI Content & Commerce Agent (white-label WordPress plugin)

A neutral-branded WordPress plugin that imports content, enriches it with AI,
researches merchant prices with a BetterSearch-style pipeline, suggests prices,
and writes Yoast SEO metadata. All external endpoints, model keys and tokens are
configured by the site owner. Nothing is bundled and no branding is hard-coded
in the UI — the brand name is filterable.

## White labelling

- Plugin name, admin title and descriptions are neutral.
- Set **Brand name shown to editors** in the admin page, or use:
  `add_filter( 'pca_brand', fn() => 'Your Brand' );`
- Internal option/function prefix `pca_` is kept for upgrade safety; it is not
  visible to editors.
- Repo name is neutral; no POS-specific wording remains in user-facing text.

## Features

### Content import
- RSS feeds via WordPress SimplePie; deduplication through `_pca_guid`.
- X.com posts via your own bearer token or an RSS bridge template.
- Posts default to **draft** for review.
- AI enrichment (optional): rewrite title, summary, focus keyphrase and category
  using any OpenAI-compatible chat endpoint you configure.

### Merchant research (BetterSearch-inspired)
Pipeline: plan query variants → gather from a compliant source → dedupe/rank →
fetch top sources → extract prices → fact-check each price against the fetched
text → store a report with confidence labels.

Sources (all legitimate; no anti-bot bypass):
- **Google News RSS** — public feed, no key.
- **Google Custom Search JSON API** — your own key and CX, subject to your quota.
- **DeepSeek Search API** — your own key and endpoint.
- **Configured MCP endpoint** — your own bridge.

The plugin never scrapes `google.com/search`, rotates user agents to evade
blocks, solves CAPTCHAs, or bypasses rate limits or account restrictions. See
`docs/GOOGLE-COMPLIANCE.md`.

### Pricing
- Calls configured Digikala/Torob MCP-style endpoints with JSON-RPC `tools/call`.
- Suggested price = `max(lowest verified price − undercut, cost × (1 + margin%))`.
- Dry-run by default; auto-apply writes WooCommerce prices by SKU or a local
  price table with `[pca_price sku="..."]`.
- `bridges/mock-mcp.mjs` is a local test server, not a real merchant integration.

### Merchant bridge (from / to)
- `from`: POST to `/wp-json/posmana-agent/v1/merchant/inbound` with
  `X-Merchant-Token` to create a draft post from an external merchant.
- `to`: price suggestions are POSTed to your configured outbound URL with the
  same token header.
- Modes: `off`, `from`, `to`, `both`. Off by default.

### SEO
- Writes `_yoast_wpseo_focuskw`, `_yoast_wpseo_metadesc` on imported/enriched posts.
- Admin checklist covers Yoast status, drafts, canonical source links and dry-run pricing.

## REST API

Routes require `manage_options` and a REST nonce, except merchant inbound which
uses the shared token:

- `GET  /wp-json/posmana-agent/v1/status`
- `POST /wp-json/posmana-agent/v1/sync/feeds`
- `POST /wp-json/posmana-agent/v1/sync/prices`
- `POST /wp-json/posmana-agent/v1/research/<sku>`
- `POST /wp-json/posmana-agent/v1/mcp-test`
- `POST /wp-json/posmana-agent/v1/merchant/inbound`

## Two editions

- **WordPress edition** — this plugin, installed in `wp-content/plugins/`.
- **Worker edition** — `worker/`, the same agent logic as a small Node/Cloudflare
  worker with JSON-file state. No WordPress install needed, so it fits on a
  machine with very little free space.

Run the worker edition locally:

```bash
cd worker
npm test
npm run dev            # http://127.0.0.1:8786/api/health
```

Deploy to Cloudflare free tier:

```bash
cd worker
npx wrangler deploy    # uses wrangler.toml, no database required
```

Worker state lives in memory on Cloudflare and in `worker/data/worker.json`
locally. Configure behaviour with environment variables documented in
`worker/README.md`.

## Install (WordPress edition)

Upload the folder to `wp-content/plugins/` and activate. Optional: install
Yoast SEO. Configure settings under **Tools → AI Content & Commerce Agent**.

## Verification

All PHP files pass `php -l` (portable PHP 8.3.37); JS files pass `node --check`;
the Worker edition passes 13 `node:test` tests and its HTTP API was smoke-tested
with real requests; the mock MCP bridge returned the expected JSON-RPC payload. No live Google, X,
Digikala or Torob requests were made during development. No credentials are in
the repository.
