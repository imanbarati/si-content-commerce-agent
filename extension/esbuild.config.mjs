import esbuild from 'esbuild';
import { copyFileSync, mkdirSync, cpSync, existsSync } from 'fs';
import { resolve } from 'path';

const isDev = process.argv.includes('--dev');
const ROOT = resolve(process.cwd());
const DIST = resolve(ROOT, 'dist');

await esbuild.build({
  entryPoints: {
    background: 'background.js',
    popup: 'popup.js',
    options: 'options.js',
  },
  bundle: true,
  format: 'esm',
  outdir: DIST,
  target: 'chrome100',
  platform: 'browser',
  splitting: false,
  sourcemap: isDev,
  minify: !isDev,
  treeShaking: true,
  legalComments: 'none',
  define: {
    'process.env.NODE_ENV': isDev ? '"development"' : '"production"',
  },
  loader: {
    '.js': 'js',
    '.mjs': 'js',
  },
  banner: {
    js: '// SI Content & Commerce Agent - Extension Bundle',
  },
});

console.log('Build complete -> dist/');

// Copy static assets
const assets = [
  { src: 'icons', dest: 'icons' },
  { src: 'privacy.html', dest: 'privacy.html' },
  { src: 'popup.html', dest: 'popup.html' },
  { src: 'options.html', dest: 'options.html' },
  { src: 'popup.css', dest: 'popup.css' },
  { src: 'manifest.json', dest: 'manifest.json' },
];

for (const asset of assets) {
  const src = resolve(ROOT, asset.src);
  const destPath = resolve(DIST, asset.dest);
  if (existsSync(src)) {
    if (asset.src.endsWith('.html') || asset.src.endsWith('.css') || asset.src.endsWith('.json')) {
      cpSync(src, destPath, { recursive: true });
    } else {
      mkdirSync(destPath, { recursive: true });
      cpSync(src, destPath, { recursive: true });
    }
    console.log('Copied ' + asset.src + ' -> dist/');
  }
}

console.log('All assets copied');
