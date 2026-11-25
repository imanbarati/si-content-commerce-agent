# Privacy hygiene — what is implemented

The `watermarks-remover` project separates two different things. This plugin
implements only the deterministic privacy layer, for content you own.

Implemented in both editions (WordPress `includes/privacy.php`, Worker
`worker/src/privacy.mjs`):

- removes zero-width and invisible format characters (`U+200B–U+200D`, `U+FEFF`,
  `U+2060`, soft hyphen, etc.);
- removes bidi controls and tag characters that can alter display order;
- normalizes exotic spaces to ordinary spaces;
- collapses double spaces left behind.

It is exposed as `/wp-json/posmana-agent/v1/privacy/clean` (WordPress, requires
`manage_options`) and `/api/privacy/clean` (Worker). It is a hygiene pass on
your own drafts, not a detector-evasion tool.

Explicitly not implemented:

- statistical text-watermark removal by rewriting/paraphrasing;
- stripping C2PA, SynthID, EXIF or other provenance signals from media;
- any workflow whose purpose is to make AI-generated content look human-authored.

Those belong to detection evasion, not privacy, and are out of scope. If you
need licensed media cleanup, use the source asset or the original tool's export
options rather than modifying this plugin.
