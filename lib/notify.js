/**
 * Order notification mail for the storefront's /pickup flow.
 *
 * When a collection customer presses Place Order, this sends two emails: a
 * confirmation to the customer, and the same order plus their contact details
 * to the bakery. The markup for both lives in lib/email-template.js; this
 * module is the part that decides what is true.
 *
 * The storefront's money rule holds here too: this service does no arithmetic
 * on money. The browser sends product ids and quantities only, and this
 * re-prices the whole basket through the bridge plugin's own
 * POST /wp-json/lilloaves/v1/quote before writing a single figure into an
 * email. Product names, pack-size labels and the pickup date/slot wording are
 * read back from WordPress too, so nothing a browser sent decides what the
 * bakery is told they sold.
 *
 * This creates no WooCommerce order — the /pickup flow does not yet place
 * one. These emails are currently the only record an order happened, and the
 * order number on them is generated here (see lib/order-number.js).
 */

import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import nodemailer from 'nodemailer'
import { formatPrice } from './money.js'
import { customerEmail, bakeryEmail, IMAGES } from './email-template.js'
import { makeOrderNumber } from './order-number.js'

// Same permissive test the storefront's Pickup form uses - enough to reject
// "not an address at all" without turning away a real customer.
const EMAIL_PATTERN = /^\S+@\S+\.\S+$/

// A basket far larger than the bakery sells, present only so a malformed or
// hostile request cannot make this service fan out an unbounded upstream
// call. Real carts are single digits.
const MAX_LINES = 50
const MAX_QTY = 99

const UPSTREAM_TIMEOUT_MS = 8000

const BRAND = "Lil' Loaves Bakery"

/**
 * The template's three transparent PNGs, read once at module load and sent as
 * CID attachments on every message. `cid:` is the one embedding method Gmail
 * doesn't strip - it drops `data:` URIs - and inline attachments mean the
 * images work with no image host to keep alive.
 */
const ATTACHMENTS = IMAGES.map(({ cid, file }) => ({
  cid,
  filename: file,
  content: readFileSync(fileURLToPath(new URL(`../assets/${file}`, import.meta.url))),
  contentDisposition: 'inline',
}))

async function getJson(url, init) {
  const response = await fetch(url, {
    ...init,
    signal: AbortSignal.timeout(UPSTREAM_TIMEOUT_MS),
  })
  if (!response.ok) return null
  return response.json()
}

/* Normalises the client's items to the exact {id, qty, variation_id?} shape
   the bridge's /quote documents, dropping anything that isn't a plain
   positive integer rather than forwarding it and hoping. */
export function normaliseItems(raw) {
  if (!Array.isArray(raw) || raw.length === 0 || raw.length > MAX_LINES) return null

  const items = []
  for (const line of raw) {
    const id = Number(line?.id)
    const qty = Number(line?.qty)
    if (!Number.isInteger(id) || id <= 0) return null
    if (!Number.isInteger(qty) || qty <= 0 || qty > MAX_QTY) return null

    const variationId = Number(line?.variation_id ?? 0)
    if (!Number.isInteger(variationId) || variationId < 0) return null

    items.push(variationId ? { id, qty, variation_id: variationId } : { id, qty })
  }
  return items
}

/**
 * The pickup store, date and slot the customer chose, resolved back to the
 * bakery's own wording from GET /pickup - and rejected if the bakery doesn't
 * actually offer them. A confirmation email promising a collection slot that
 * was never on the schedule is worse than no email, so this fails closed
 * rather than echoing whatever the browser claimed.
 *
 * `dateLabel` is "2 Aug, Sunday" per Figma 314:263 - the server's own `label`
 * and `weekday`, joined, never a date computed here.
 */
async function resolvePickup(base, chosen) {
  const config = await getJson(`${base}/wp-json/lilloaves/v1/pickup`, {
    headers: { Accept: 'application/json' },
  })
  const stores = config?.stores ?? []
  if (stores.length === 0) return null

  // Matches the storefront: only the first configured store is ever offered,
  // so an absent/mismatched id falls back to it rather than failing.
  const store = stores.find((s) => String(s.id) === String(chosen?.store)) ?? stores[0]

  const date = (store.dates ?? []).find((d) => d.date === chosen?.date)
  if (!date) return null

  const slot = (store.slots ?? []).find((s) => `${s.start}-${s.end}` === chosen?.slot)
  if (!slot) return null

  return {
    storeName: store.name,
    storeAddress: store.address ?? '',
    dateLabel: date.weekday ? `${date.label}, ${date.weekday}` : date.label,
    slotLabel: slot.label,
  }
}

/**
 * Product names for the quoted lines, and the pack-size label where a line
 * selected one. Both read from WordPress, never from the request body: the
 * bakery is picking these items off a shelf, so the name in their email has
 * to be the catalogue's, not one a browser supplied.
 *
 * Best-effort by design - a line whose name can't be resolved still appears,
 * identified by its product id, because dropping a paid-for line from a
 * packing list is far worse than an ugly one.
 */
async function resolveNames(base, quoteLines) {
  const ids = [...new Set(quoteLines.map((l) => l.id))]

  const [products, variations] = await Promise.all([
    getJson(
      `${base}/wp-json/wc/store/v1/products?include=${ids.join(',')}&per_page=100`,
      { headers: { Accept: 'application/json' } },
    ).catch(() => null),
    getJson(`${base}/wp-json/lilloaves/v1/variations`, {
      headers: { Accept: 'application/json' },
    }).catch(() => null),
  ])

  const nameById = new Map((products ?? []).map((p) => [p.id, p.name]))
  const byParent = variations?.products ?? {}

  return quoteLines.map((line) => {
    const name = nameById.get(line.id) ?? `Product #${line.id}`
    const packSize = line.variation_id
      ? (byParent[String(line.id)] ?? []).find((v) => v.id === line.variation_id)?.name
      : null
    return { ...line, name, packSize }
  })
}

/**
 * The whole endpoint as one function of its request body, so it can be tested
 * without an HTTP server. Returns { status, body } for the caller to send.
 * `makeTransport` and `newOrderNumber` are injectable only so tests never
 * touch SMTP and can pin the order number.
 */
export async function handleNotify(
  body = {},
  { makeTransport = nodemailer.createTransport, newOrderNumber = makeOrderNumber } = {},
) {
  const base = process.env.WP_STORE_URL
  if (!base) return { status: 500, body: { ok: false, error: 'WP_STORE_URL is not set' } }

  const secret = process.env.LL_BRIDGE_SECRET
  if (!secret) return { status: 500, body: { ok: false, error: 'LL_BRIDGE_SECRET is not set' } }

  const user = process.env.GMAIL_USER
  const pass = process.env.GMAIL_APP_PASSWORD
  const bakeryInbox = process.env.ORDER_EMAIL || user
  if (!user || !pass || !bakeryInbox) {
    return { status: 500, body: { ok: false, error: 'Mail is not configured' } }
  }

  const name = String(body.contact?.name ?? '').trim()
  const email = String(body.contact?.email ?? '').trim()
  const phone = String(body.contact?.phone ?? '').trim()
  if (!name || !phone || !EMAIL_PATTERN.test(email)) {
    return {
      status: 400,
      body: { ok: false, error: 'Name, a valid email address and a phone number are required' },
    }
  }
  const contact = { name, email, phone }

  const items = normaliseItems(body.items)
  if (!items) return { status: 400, body: { ok: false, error: 'Invalid items' } }

  let pickup
  let quote
  try {
    pickup = await resolvePickup(base, body.pickup)
    if (!pickup) {
      return { status: 400, body: { ok: false, error: 'That collection slot is no longer available' } }
    }

    quote = await getJson(`${base}/wp-json/lilloaves/v1/quote`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-LL-Secret': secret },
      body: JSON.stringify({ items, fulfilment: 'pickup', postcode: '', coupon: body.coupon ?? '' }),
    })
  } catch (error) {
    // Never log the body - it carries the customer's name, email and phone.
    console.error('notify: upstream unreachable', error.message)
    return { status: 502, body: { ok: false, error: 'Upstream unreachable' } }
  }

  if (!quote) return { status: 502, body: { ok: false, error: 'Upstream error' } }
  if (quote.errors?.length) return { status: 409, body: { ok: false, error: quote.errors.join(' ') } }
  if (!quote.lines?.length) return { status: 400, body: { ok: false, error: 'Nothing to order' } }

  const lines = await resolveNames(base, quote.lines)
  const orderNumber = newOrderNumber()
  // The one place a figure is formatted, from the server's own total.
  const totalFormatted = formatPrice(quote.currency, String(quote.total))
  const payload = { contact, lines, pickup, totalFormatted, orderNumber }

  const transport = makeTransport({
    service: process.env.MAIL_SERVICE || 'gmail',
    auth: { user, pass },
  })
  const from = `"${BRAND}" <${user}>`

  try {
    const forBakery = bakeryEmail(payload)
    const forCustomer = customerEmail(payload)

    // The bakery's copy is the one that must not be lost - it is the only
    // record this order happened, since the flow places no WooCommerce order.
    // Sent first and awaited on its own so a bounce at the customer's address
    // cannot take it down with it.
    await transport.sendMail({
      from,
      to: bakeryInbox,
      replyTo: contact.email,
      subject: forBakery.subject,
      html: forBakery.html,
      attachments: ATTACHMENTS,
    })

    await transport.sendMail({
      from,
      to: contact.email,
      replyTo: bakeryInbox,
      subject: forCustomer.subject,
      html: forCustomer.html,
      attachments: ATTACHMENTS,
    })

    // Returned so the storefront can show it if it ever wants to; the
    // customer's copy of it is the email.
    return { status: 200, body: { ok: true, orderNumber } }
  } catch (error) {
    console.error('notify: send failed', error.message)
    return { status: 502, body: { ok: false, error: 'Could not send the confirmation email' } }
  }
}
