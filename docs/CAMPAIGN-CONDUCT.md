# Campaign conduct — what is in and what is out

## Out of scope

I did not add proactive autonomous bot swarms, fake traffic, fake engagement,
click fraud, SERP scraping, ad-account evasion, or anything designed to
manipulate Google Ads or search results. Those violate platform terms, can be
illegal, and put the site and its owner at risk. "Viral campaign research" is
implemented only as public-source trend research, not synthetic amplification.

## Implemented instead

- **Keyword research**: compliant seed-variant generation and research through
  Google News RSS, Google Custom Search API (your key), DeepSeek Search or your
  MCP endpoint. No SERP scraping.
- **Schema.org JSON-LD**: Product and Article markup output alongside Yoast SEO,
  with distinct `@id` values so the graphs do not conflict.
- **Affiliate links**: UTM-tagged links for Shopify, WooCommerce, AliExpress, CJ,
  Spocket, Zendrop and Printify. Tags are configured by the site owner; links are
  marked `sponsored nofollow`. No account automation.
- **Campaign audit**: a checklist covering real traffic, official APIs, draft
  review, schema and disclosed affiliate links.

## Legitimate automation that can be added later

With your own credentials, an adapter could call the official Google Ads API for
reporting and bid adjustments, or Google Search Console for query data. Each
adapter must respect quotas and platform policies. No bot network is involved.
