/** Deterministic hidden-character hygiene for text you own. No watermark rewriting. */
export function cleanInvisible(text) {
  return String(text || '')
    .replace(/[\u200B-\u200D\uFEFF\u2060\u180E\u00AD\u202A-\u202E\u2066-\u2069\u200E\u200F]/gu, '')
    .replace(/[\u{E0000}-\u{E007F}]/gu, '')
    .replace(/[\u00A0\u2000-\u200A\u202F\u205F\u3000]/gu, ' ')
    .replace(/[ \t]{2,}/g, ' ')
}
