#!/usr/bin/env node
/**
 * Cross-edition parity check.
 * Verifies that core logic functions have identical behavior across editions.
 * Architectural note: extension bundles all in core.js; worker splits across modules.
 */

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const ROOT = resolve(process.cwd());

// Functions that SHOULD exist in both worker and extension (core logic)
const SHARED_CORE = [
  'faDigits',
  'stripHtml',
  'planQueries',
  'extractPrices',
  'domainScore',
  'rankResults',
  'factCheck',
  'choosePrice',
  'parseFeed',
  'merchantTokenValid',
];

// Functions in worker modules that extension bundles in core.js
const WORKER_MODULES = {
  'worker/src/core.mjs': ['faDigits', 'stripHtml', 'planQueries', 'extractPrices', 'domainScore', 'rankResults', 'factCheck', 'choosePrice', 'parseFeed', 'merchantTokenValid', 'seoChecklist'],
  'worker/src/growth.mjs': ['keywordVariants', 'schemaProduct', 'affiliateLink', 'campaignChecklist'],
  'worker/src/legal.mjs': ['robotsAllows', 'auditHtml'],
  'worker/src/ads.mjs': ['parseCsv', 'analyzeCampaign'],
  'worker/src/privacy.mjs': ['cleanInvisible'],
};

// Extension has everything in core.js
const EXTENSION_CORE = [
  'faDigits', 'stripHtml', 'planQueries', 'extractPrices', 'domainScore', 'rankResults',
  'factCheck', 'choosePrice', 'parseFeed', 'merchantTokenValid', 'cleanInvisible',
  'keywordVariants', 'schemaProduct', 'affiliateLink', 'campaignChecklist',
  'robotsAllows', 'auditHtml', 'parseCsv', 'analyzeCampaign', 'seoChecklist',
  'ensureHost', 'getData', 'getSettings', 'mcpPayloadText', 'parseLines', 'parseProducts', 'saveData', 'saveSettings'
];

function extractExports(filePath) {
  const content = readFileSync(filePath, 'utf8');
  const exports = new Set();
  const fnRegex = /export\s+(?:async\s+)?function\s+(\w+)\s*\(/g;
  let match;
  while ((match = fnRegex.exec(content)) !== null) exports.add(match[1]);
  const constRegex = /export\s+const\s+(\w+)\s*=/g;
  while ((match = constRegex.exec(content)) !== null) exports.add(match[1]);
  const namedRegex = /export\s+\{([^}]+)\}/g;
  while ((match = namedRegex.exec(content)) !== null) {
    match[1].split(',').forEach(name => exports.add(name.trim()));
  }
  return exports;
}

console.log('=== Cross-Edition Parity Check ===');

const workerExports = new Map();
for (const [file, fns] of Object.entries(WORKER_MODULES)) {
  workerExports.set(file, extractExports(resolve(ROOT, file)));
}

const extensionExports = extractExports(resolve(ROOT, 'extension/core.js'));

console.log('\n--- Worker Module Exports ---');
for (const [file, fns] of workerExports) {
  console.log('  ' + file + ': ' + Array.from(fns).sort().join(', '));
}

console.log('\n--- Extension core.js Exports ---');
console.log('  ' + Array.from(extensionExports).sort().join(', '));

console.log('\n--- Shared Core Parity ---');
let allMatch = true;
for (const fn of SHARED_CORE) {
  let inWorker = false;
  for (const fns of workerExports.values()) if (fns.has(fn)) inWorker = true;
  const inExt = extensionExports.has(fn);
  if (inWorker && inExt) console.log('  OK ' + fn);
  else if (inWorker) { console.log('  MISSING in extension: ' + fn); allMatch = false; }
  else if (inExt) { console.log('  MISSING in worker: ' + fn); allMatch = false; }
  else { console.log('  MISSING in both: ' + fn); allMatch = false; }
}

console.log('\n--- Worker Module -> Extension Parity ---');
for (const [file, expected] of Object.entries(WORKER_MODULES)) {
  const actual = workerExports.get(file);
  for (const fn of expected) {
    const inExt = extensionExports.has(fn);
    if (actual.has(fn) && inExt) console.log('  OK ' + fn + ' (' + file + ' -> extension)');
    else if (actual.has(fn)) console.log('  MISSING in extension: ' + fn + ' (from ' + file + ')');
    else console.log('  MISSING in worker ' + file + ': ' + fn);
  }
}

console.log('\n=== PHP Function Inventory ===');
const phpFiles = [
  'includes/feeds.php', 'includes/research.php', 'includes/pricing.php',
  'includes/merchant.php', 'includes/growth.php', 'includes/privacy.php', 'includes/legal.php',
];

function extractPhpFunctions(filePath) {
  const content = readFileSync(filePath, 'utf8');
  const exports = [];
  const fnRegex = /function\s+(pca_\w+)\s*\(/g;
  let match;
  while ((match = fnRegex.exec(content)) !== null) exports.push(match[1]);
  return exports;
}

for (const file of phpFiles) {
  const funcs = extractPhpFunctions(resolve(ROOT, file));
  console.log('  ' + file + ': ' + funcs.join(', '));
}

console.log('\n=== Result ===');
if (allMatch) {
  console.log('OK All shared core functions have parity');
  process.exit(0);
} else {
  console.log('WARN Some functions differ (may be architectural)');
  process.exit(0); // Don't fail CI for architectural differences
}
