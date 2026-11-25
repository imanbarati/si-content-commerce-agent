# SI Agent — quick start

Three editions, one config model. Nothing here creates accounts, posts to ad
platforms, generates traffic or changes bids.

## 1. Worker / local server (recommended first)

```bash
cd worker
cp .env.example .env          # edit OWNED_DOMAINS first
npm install
npm run dev
```

Check it: `curl http://127.0.0.1:8786/api/health`

Set `AUTONOMY=on` in `.env`, then run a read-only cycle:

```bash
curl -X POST http://127.0.0.1:8786/api/autonomy/run
```

### Computer use (your browser, your domains)

Start Chrome with `--remote-debugging-port=9222`, keep only your own tab open,
then:

```bash
COMPUTER_ORIGINS=https://your-site.example node computer.mjs https://your-site.example audit
```

The inspector refuses any origin not in `COMPUTER_ORIGINS`. Actions are
read-only; it never types credentials or submits forms.

### Campaign analysis

Save a Google Ads CSV export with columns `campaign,impressions,clicks,cost,conversions`:

```bash
curl -X POST http://127.0.0.1:8786/api/ads/analyze \
  -H 'content-type: application/json' \
  -d '{"csv":"campaign,impressions,clicks,cost,conversions\nA,1000,5,50,0"}'
```

## 2. Chrome extension

`chrome://extensions` → Developer mode → Load unpacked → `extension/`.
Then:
- **Settings** → fill fields once, use **Export JSON** / **Import JSON** to copy config to another browser.
- **Audit this tab** inspects the current page after a user gesture (`activeTab`).
- Paste a campaign CSV into the text box → **Analyze ads CSV**.

## 3. WordPress plugin

Upload the plugin, open **SI Agent** in wp-admin. Fill feeds, products and
owned domains, then use the one-click buttons: **Run checklist** and
**Run owned-domain audit**.

## Legal boundary

See `docs/LEGAL-GROWTH.md`. No bot swarms, fake traffic, fake engagement,
click fraud, ad-account evasion, CAPTCHA bypass or automated spend changes.
