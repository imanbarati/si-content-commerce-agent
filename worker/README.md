# Worker edition — no WordPress needed

This is the same agent logic as the plugin, running as a small Node server or a
Cloudflare Worker. State is a JSON file locally and memory on Cloudflare. No
database, no WordPress, minimal disk use.

## Run locally

```bash
cd worker
npm test
npm run dev    # http://127.0.0.1:8786/api/health
```

Set `PORT` and `DATA_FILE` to change the port/state path.

## Endpoints

- `GET  /api/health`
- `GET  /api/status`
- `GET  /api/posts`
- `GET  /api/config` (never returns secrets)
- `POST /api/feeds/sync`
- `POST /api/prices/sync`
- `POST /api/research/<sku>`
- `POST /api/merchant/inbound` (requires `x-merchant-token`)

## Environment variables

| Variable | Purpose |
|---|---|
| `FEEDS` | RSS URLs, comma or newline separated |
| `PRODUCTS` | `sku\|name\|query\|cost` lines |
| `RESEARCH_PROVIDER` | `google_news`, `google_cse`, `deepseek`, `mcp` |
| `GOOGLE_CSE_KEY`, `GOOGLE_CSE_CX` | Official Custom Search API credentials |
| `DEEPSEEK_ENDPOINT`, `DEEPSEEK_KEY` | DeepSeek Search API |
| `MCP_DIGIKALA_URL`, `MCP_TOROB_URL`, `MCP_TOOL` | Your own MCP bridges |
| `MERCHANT_MODE` | `off`, `from`, `to`, `both` |
| `MERCHANT_TO_URL`, `MERCHANT_TOKEN` | Outbound webhook and shared token |
| `UNDERCUT`, `MARGIN` | Pricing guardrails |

No secret is written to source or returned by `/api/config`.

## Cloudflare free tier

```bash
cd worker
npx wrangler deploy
```

`wrangler.toml` uses a Worker with no D1/KV dependency. Worker state is
per-isolate memory, so it is suitable for stateless shielding or demos; add a KV
binding later if you need persistence. `workers_dev = true` gives a free
`*.workers.dev` URL.

## Compliance

Research uses Google News RSS or the official Custom Search API with your key.
There is no CAPTCHA bypass, IP rotation or search-result scraping. See
`../docs/GOOGLE-COMPLIANCE.md` and `../docs/SEO-ETHICS.md`.
