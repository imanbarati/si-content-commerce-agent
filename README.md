# Posmana Content Agent — WordPress plugin

A single WordPress plugin that:

1. imports RSS feeds and X.com items into posts;
2. asks configurable MCP endpoints for competitor prices and suggests (or applies) prices;
3. writes Yoast SEO focus keyphrase and meta description on imported posts;
4. exposes a small REST API and an admin page under **Tools → Posmana Agent**.

No credentials, API keys, tokens or scraped data are bundled. Nothing is sent
anywhere except the endpoints you configure yourself.

## Install

Copy this folder to `wp-content/plugins/posmana-content-agent` and activate it,
or zip the contents (files at the zip root) and upload through wp-admin →
Plugins → Add New.

Optional: install Yoast SEO. The plugin writes Yoast meta fields when it is
active and still writes plain post meta when it is not.

## Feed import

- Add RSS URLs under **Feeds** in the admin page. Feeds are fetched with
  WordPress's bundled SimplePie (`fetch_feed`).
- Duplicate protection uses post meta `_pca_guid`, so reruns do not create
  duplicate posts.
- Posts are created as **draft** by default. Change this only after reviewing
  quality and duplicate-content risk.
- Imported posts include a `nofollow noopener` link to the original source.
- Cron runs on your chosen interval; activation schedules it automatically.

### X.com

Choose one:

- Supply your own X API bearer token under **Feeds**. The plugin calls
  `/2/users/by/username/{handle}` and `/2/users/{id}/tweets`. API access may
  require a paid X plan.
- Or supply an RSS bridge URL template using `{handle}`, e.g.
  `https://your-bridge.example/{handle}`. Bridge reliability and terms are your
  responsibility.

The plugin does not bypass authentication, create accounts, or scrape private
data. Handles that cannot be read are logged as errors.

## Pricing via MCP

The bundled `bridges/mock-mcp.mjs` is only a local test server. Real Digikala and
Torob MCP endpoints are **not** included: you must provide them (or write a
small bridge that exposes their public data through the same JSON contract).
The plugin does not scrape either site directly.

Contract — POST JSON-RPC to the configured endpoint:

```json
{
  "jsonrpc": "2.0",
  "id": "uuid",
  "method": "tools/call",
  "params": {
    "name": "search_product",
    "arguments": { "query": "posmana mini" }
  }
}
```

Expected response:

```json
{
  "jsonrpc": "2.0",
  "id": "uuid",
  "result": { "content": [{ "type": "text", "text": "{"price":12900000}" }] }
}
```

Any numeric `price`, `min_price`, `lowest_price` or `final_price` key is
accepted, including nested JSON.

Price rules:

- `new price = max(lowest competitor price - undercut, cost × (1 + margin%))`.
- Results go to `pca_price_suggestions` and are shown in the admin table.
- Auto-apply is **off by default**. When enabled, WooCommerce product prices are
  updated by SKU if WooCommerce is active; otherwise a local `pca_prices` option
  is updated and exposed with `[pca_price sku="posmana-mini"]`.

Test locally:

```bash
MOCK_PRICE=12900000 node bridges/mock-mcp.mjs
# set the Digikala MCP endpoint to http://127.0.0.1:8789/ in the plugin
```

## Yoast SEO

On import the plugin writes:

- `_yoast_wpseo_focuskw` — focus keyphrase from the item title;
- `_yoast_wpseo_metadesc` — trimmed item description;
- `_pca_seo_focus` — plain copy for audit.

The admin page shows a checklist (Yoast active, drafts by default, canonical
source links, dry-run pricing). It does not modify Yoast's code or settings.

## REST API

All routes require `manage_options` and a REST nonce:

- `GET  /wp-json/posmana-agent/v1/status`
- `POST /wp-json/posmana-agent/v1/sync/feeds`
- `POST /wp-json/posmana-agent/v1/sync/prices`
- `POST /wp-json/posmana-agent/v1/mcp-test` with `endpoint`, `tool`, `query`

## Security and limits

- Capability checks, nonces, sanitisation and escaped output throughout.
- URL validation allows only http/https with a host.
- Secrets are stored only in your WordPress options table, never in this repo.
- Price suggestions are not financial advice; review before applying.
- No promise of avoiding bans or blocks on X, Digikala, Torob or any site.

## Verification performed

All PHP files pass `php -l` with portable PHP 8.3.37; both JS files pass
`node --check`. The JSON-RPC contract was exercised against the bundled mock
bridge shape. No live X/Digikala/Torob requests were made during development.
