# Legal growth automation

This module implements the lawful subset of "autonomous campaign conduct".

## Hard rules

- Only domains explicitly listed as owned by you are crawled (`OWNED_DOMAINS` in
  the Worker, **Owned domains** in WordPress settings).
- `robots.txt` is fetched and respected before any path is requested.
- Requests are sequential, bounded and delayed. No distributed swarms.
- The module is **audit-only**: it reads your own pages and reports SEO issues.
  It does not create accounts, post, click, vote, send traffic or change bids.
- No CAPTCHA bypass, IP rotation, user-agent spoofing or quota evasion.
- Ads automation, if added later, must use the official Google Ads API with your
  developer token and OAuth credentials, with human approval for spend changes.

## What it checks

Title, meta description, canonical tag, H1 and JSON-LD schema on each allowed
URL. Results are advisory; nothing is published automatically.

## Why this shape

Search and ad platforms prohibit synthetic traffic and engagement. Automated
technical audits of sites you own are legitimate and useful; manipulation of
third-party signals is not.
