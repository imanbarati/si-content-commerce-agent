# AI spam detection and how to comply

I will not provide tactics for bypassing AI spam classifiers, cloaking content,
or rewriting generated text specifically to evade detection. That is deception
of readers and search engines, and it puts the site at real risk.

Here is what those systems generally evaluate, and the legitimate response.

## What classifiers look at (high level)

- **Duplicate or template text** across many pages, including near-duplicate
  rewrites of the same source.
- **Thin content**: little original information beyond aggregated facts.
- **Keyword stuffing** and unnatural repetition in titles, headings and body.
- **Metadata mismatch**: title/description promising something the page lacks.
- **Link patterns**: link farms, paid links without disclosure, excessive outbound
  links to low-quality domains.
- **Hidden or cloaked content**: text shown to crawlers but not users.
- **Lack of provenance**: no author, no source citation, no date, no editorial
  review.
- **Mass-generated pages** published at high velocity with no human oversight.

## Legitimate countermeasures

1. Publish original analysis or commentary around imported facts, not just a
   rewritten feed item.
2. Keep drafts on by default; review before publishing.
3. Cite the original source with a `nofollow` link, and keep the date.
4. Give every post a real focus keyphrase and a description that matches the body.
5. Use author bios and editorial notes; disclose AI assistance where appropriate.
6. Avoid publishing dozens of near-identical pages in one run; stagger and edit.
7. Keep internal links natural and descriptive.
8. Monitor Search Console for manual actions and fix the cause rather than
   disguising it.

## How this plugin supports that

- Imports and research output are drafts by default.
- Each imported post links back to its source.
- AI enrichment writes metadata, and the editor reviews it.
- The fact-check step requires a price to appear in a fetched source.
- The SEO checklist in the admin page tracks these compliance signals.

If a site gets flagged, the correct fix is to improve or remove the low-quality
content — not to make it harder for classifiers to detect.
