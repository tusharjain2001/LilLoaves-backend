/**
 * Writes both emails to preview/*.html with sample data, so the template can
 * be eyeballed in a browser without sending anything.
 *
 *   npm run preview:email
 *   open preview/customer.html
 *
 * The CID attachments are rewritten to data: URIs **for the preview only** —
 * a browser has no attachments to resolve, and Gmail strips data: URIs, which
 * is exactly why the real emails use cid:. Do not copy this rewrite into
 * lib/notify.js.
 *
 * To check dark mode: macOS System Settings → Appearance → Dark, or in
 * Chrome DevTools → Rendering → "Emulate prefers-color-scheme: dark".
 */

import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { customerEmail, bakeryEmail, IMAGES } from '../lib/email-template.js'

const root = new URL('..', import.meta.url)
const asDataUri = (file) =>
  `data:image/png;base64,${readFileSync(fileURLToPath(new URL(`assets/${file}`, root))).toString('base64')}`

const inlineImages = (html) =>
  IMAGES.reduce((acc, { cid, file }) => acc.split(`cid:${cid}`).join(asDataUri(file)), html)

const SAMPLE = {
  contact: { name: 'Moona Patel', email: 'moona@example.com', phone: '(714) 555-0123' },
  lines: [
    { name: 'Sourdough', qty: 1, packSize: null },
    { name: 'Blueberry Muffins', qty: 1, packSize: 'Pack of 4' },
    { name: "Doc's Cheddar Crackers", qty: 1, packSize: '5 oz' },
  ],
  pickup: {
    storeName: 'Orange County Store',
    storeAddress: '1234 Example Ave, Orange County, CA',
    dateLabel: '2 Aug, Sunday',
    slotLabel: '2:30 PM – 3:00 PM',
  },
  totalFormatted: '$39.00',
  orderNumber: 'LL-10324',
}

const out = fileURLToPath(new URL('preview/', root))
mkdirSync(out, { recursive: true })

for (const [name, build] of [
  ['customer', customerEmail],
  ['bakery', bakeryEmail],
]) {
  const { subject, html } = build(SAMPLE)
  writeFileSync(`${out}${name}.html`, inlineImages(html))
  console.log(`${name.padEnd(9)} ${subject}`)
}

console.log(`\nwritten to ${out}`)
