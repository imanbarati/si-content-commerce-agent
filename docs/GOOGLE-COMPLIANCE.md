# Google restrictions — what this plugin does and does not do

The plugin does **not** bypass Google restrictions. There is no code to defeat
CAPTCHAs, emulate a human, rotate IPs or user agents, ignore `robots.txt`, or
evade quotas and account bans. Doing so would violate Google's terms and could
harm the site owner's account.

Supported compliant sources:

1. **Google News RSS** — a public feed intended for syndication. Query URL:
   `https://news.google.com/rss/search?q=...&hl=fa&gl=IR&ceid=IR:fa`.
   Keep request volume reasonable; the plugin schedules hourly sync by default.
2. **Google Custom Search JSON API** — requires the site owner's API key and
   search engine CX. Quotas and billing are the owner's responsibility.
3. **Sitemaps / site feeds** for domains you are allowed to read, respecting
   `robots.txt` and rate limits.

If Google blocks a request, the plugin logs an error and moves on. It does not
retry with evasion techniques. For reliable merchant pricing, configure your own
MCP bridge for Digikala/Torob rather than relying on search scraping.
