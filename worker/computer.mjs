#!/usr/bin/env node
import { inspectPage } from './src/computer.mjs'

const url = process.argv[2]
const action = process.argv[3] || 'audit'
if (!url) {
  console.error('Usage: node computer.mjs <url> [audit|text|links]')
  console.error('Set COMPUTER_ORIGINS=https://your-site.example and CHROME_DEBUG_PORT=9222')
  process.exit(2)
}
const origins = String(process.env.COMPUTER_ORIGINS || '').split(/[\n,]/).map((item) => item.trim()).filter(Boolean)
if (!origins.length) {
  console.error('COMPUTER_ORIGINS is empty. Only add domains you own.')
  process.exit(2)
}
try {
  const result = await inspectPage({ url, origins, action, debugPort: Number(process.env.CHROME_DEBUG_PORT || 9222) })
  console.log(typeof result === 'string' ? result : JSON.stringify(result, null, 2))
} catch (error) {
  console.error(error.message)
  process.exit(1)
}
