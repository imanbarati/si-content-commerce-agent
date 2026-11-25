# Chrome extension edition

Manifest V3 extension that runs the same agent logic in the browser. No WordPress
install and no local server needed.

## Install

1. Open `chrome://extensions`.
2. Enable Developer mode.
3. Choose **Load unpacked** and select this `extension/` folder.
4. Open the extension popup, then **Settings** to configure feeds, products and endpoints.

## Permissions

- `storage` — keeps settings and local results in this browser only.
- `alarms` — hourly feed sync while the browser runs.
- `optional_host_permissions` — Chrome asks for access to an origin only when
  you configure an endpoint there. No blanket host permission is granted at
  install time.

No credentials are bundled. Keys and tokens entered in Settings live in
`chrome.storage.local` on your machine.

## What it does

- Sync RSS feeds into a local post list with GUID deduplication.
- Merchant research with Google News RSS, Google Custom Search API (your key),
  DeepSeek Search (your key) or your MCP endpoint. Fact-checks prices against
  fetched sources.
- Price suggestions from your configured MCP bridges, with undercut/margin rules.
- Merchant bridge mode `off/from/to/both` with a shared token.
- Privacy hygiene: removes hidden Unicode controls and exotic spaces from text you own.

## Compliance

No Google SERP scraping, no CAPTCHA bypass, no IP rotation, no watermark removal.
See `../docs/GOOGLE-COMPLIANCE.md`, `../docs/SEO-ETHICS.md` and `../docs/PRIVACY.md`.

## Tests

```bash
cd extension
npm test
```
