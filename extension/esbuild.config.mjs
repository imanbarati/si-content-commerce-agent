import esbuild from 'esbuild';

const isDev = process.argv.includes('--dev');

await esbuild.build({
  entryPoints: {
    background: 'background.js',
    popup: 'popup.js',
    options: 'options.js',
  },
  bundle: true,
  format: 'esm',
  outdir: 'dist',
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
