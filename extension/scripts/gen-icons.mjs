#!/usr/bin/env node
// Generate PNG icons from SVG using sharp
import sharp from 'sharp';
import { readFileSync, writeFileSync, mkdirSync } from 'fs';
import { resolve } from 'path';

const ROOT = resolve(process.cwd());
const svg = readFileSync(resolve(ROOT, 'icons/icon.svg'));

const sizes = [16, 32, 48, 128];
mkdirSync(resolve(ROOT, 'icons'), { recursive: true });

for (const size of sizes) {
  const png = await sharp(svg).resize(size, size).png().toBuffer();
  writeFileSync(resolve(ROOT, 'icons/icon' + size + '.png'), png);
  console.log('Generated icons/icon' + size + '.png');
}

console.log('Done!');
