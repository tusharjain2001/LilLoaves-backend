import { handleNotify, normaliseItems } from './notify.js'
import { escapeHtml } from './html.js'
import { isAllowedOrigin } from './origins.js'

const sendMail = vi.fn()
const makeTransport = vi.fn(() => ({ sendMail }))

const CURRENCY = {
  currency_code: 'USD',
  currency_symbol: '$',
  currency_minor_unit: 2,
  currency_decimal_separator: '.',
  currency_thousand_separator: ',',
  currency_prefix: '$',
  currency_suffix: '',
}

const PICKUP_CONFIG = {
  stores: [
    {
      id: 'orange-county-store',
      name: 'Orange County Store',
      address: '1234 Example Ave',
      slots: [{ start: '14:00', end: '14:30', label: '2:00 PM - 2:30 PM' }],
      dates: [{ date: '2026-08-09', weekday: 'Sunday', label: '9 Aug' }],
    },
  ],
}

const QUOTE = {
  lines: [
    { id: 1, variation_id: 0, qty: 2, total: 4226, unit: 2113 },
    { id: 88, variation_id: 90, qty: 1, total: 2000, unit: 2000 },
  ],
  subtotal: 6226,
  delivery: 0,
  discount: 0,
  tax: 0,
  total: 6226,
  errors: [],
  currency: CURRENCY,
}

const PRODUCTS = [
  { id: 1, name: 'Blueberry Muffin' },
  { id: 88, name: 'Chocolate Chip Cookies' },
]

const VARIATIONS = {
  products: { 88: [{ id: 90, name: 'Box of 6', price: 2000 }] },
  currency: CURRENCY,
}

const BODY = {
  items: [
    { id: 1, qty: 2 },
    { id: 88, qty: 1, variation_id: 90 },
  ],
  contact: { name: 'Jess', email: 'jess@example.com', phone: '714-555-0123' },
  pickup: { store: 'orange-county-store', date: '2026-08-09', slot: '14:00-14:30' },
  coupon: '',
}

const ORDER_NUMBER = 'LL-10324'
const run = (body = BODY) =>
  handleNotify(body, { makeTransport, newOrderNumber: () => ORDER_NUMBER })

const sent = () => sendMail.mock.calls.map((c) => c[0])

// Routes each upstream URL to its canned payload, so a test that breaks one
// of them doesn't silently get another's response.
function mockUpstream({ pickup = PICKUP_CONFIG, quote = QUOTE } = {}) {
  global.fetch = vi.fn(async (url) => {
    const target = String(url)
    const json =
      target.includes('/lilloaves/v1/pickup') ? pickup
        : target.includes('/lilloaves/v1/quote') ? quote
          : target.includes('/lilloaves/v1/variations') ? VARIATIONS
            : target.includes('/wc/store/v1/products') ? PRODUCTS
              : null
    return { ok: json !== null, json: async () => json }
  })
}

beforeEach(() => {
  process.env.WP_STORE_URL = 'https://wp.example.com'
  process.env.LL_BRIDGE_SECRET = 'shh-secret'
  process.env.GMAIL_USER = 'bakery@example.com'
  process.env.GMAIL_APP_PASSWORD = 'app-password'
  process.env.ORDER_EMAIL = 'orders@example.com'
  process.env.ALLOWED_ORIGINS = 'https://lilloaves.com, https://lil-loaves.vercel.app'
  sendMail.mockReset().mockResolvedValue({ messageId: 'x' })
  makeTransport.mockClear()
  mockUpstream()
})

describe('origin allowlist', () => {
  it('accepts a configured storefront origin, trailing slash or not', () => {
    expect(isAllowedOrigin('https://lilloaves.com')).toBe(true)
    expect(isAllowedOrigin('https://lilloaves.com/')).toBe(true)
    expect(isAllowedOrigin('https://lil-loaves.vercel.app')).toBe(true)
  })

  it('refuses anything else, including a missing Origin', () => {
    expect(isAllowedOrigin('https://evil.example')).toBe(false)
    expect(isAllowedOrigin('')).toBe(false)
    expect(isAllowedOrigin(undefined)).toBe(false)
    // A substring of an allowed host must not pass.
    expect(isAllowedOrigin('https://lilloaves.com.evil.example')).toBe(false)
  })
})

describe('input validation', () => {
  it('says so rather than half-working when mail is not configured', async () => {
    delete process.env.GMAIL_APP_PASSWORD
    const { status, body } = await run()
    expect(status).toBe(500)
    expect(body.error).toMatch(/mail is not configured/i)
  })

  it('requires a name, a valid email and a phone number', async () => {
    for (const contact of [
      { name: '', email: 'a@b.co', phone: '1' },
      { name: 'Jess', email: 'not-an-email', phone: '1' },
      { name: 'Jess', email: 'a@b.co', phone: '  ' },
    ]) {
      const { status } = await run({ ...BODY, contact })
      expect(status).toBe(400)
    }
    expect(sendMail).not.toHaveBeenCalled()
  })

  it('rejects malformed or absurd item lists instead of forwarding them', () => {
    expect(normaliseItems([])).toBeNull()
    expect(normaliseItems([{ id: 'x', qty: 1 }])).toBeNull()
    expect(normaliseItems([{ id: 1, qty: 0 }])).toBeNull()
    expect(normaliseItems([{ id: 1, qty: 500 }])).toBeNull()
    expect(normaliseItems([{ id: 1.5, qty: 1 }])).toBeNull()
    expect(normaliseItems(Array.from({ length: 51 }, () => ({ id: 1, qty: 1 })))).toBeNull()
    // A simple product carries no variation_id at all, per /quote's contract.
    expect(normaliseItems([{ id: 1, qty: 2, variation_id: 0 }])).toEqual([{ id: 1, qty: 2 }])
    expect(normaliseItems([{ id: 88, qty: 1, variation_id: 90 }])).toEqual([
      { id: 88, qty: 1, variation_id: 90 },
    ])
  })

  it('will not confirm a collection slot the bakery does not offer', async () => {
    const { status } = await run({ ...BODY, pickup: { ...BODY.pickup, slot: '23:00-23:30' } })
    expect(status).toBe(400)
    expect(sendMail).not.toHaveBeenCalled()
  })

  it('will not send an order the server refused to price', async () => {
    mockUpstream({ quote: { ...QUOTE, errors: ['Sourdough is no longer available.'] } })
    const { status } = await run()
    expect(status).toBe(409)
    expect(sendMail).not.toHaveBeenCalled()
  })

  it('reports an unreachable WordPress rather than sending a blank order', async () => {
    global.fetch = vi.fn().mockRejectedValue(new Error('ECONNRESET'))
    const { status } = await run()
    expect(status).toBe(502)
    expect(sendMail).not.toHaveBeenCalled()
  })
})

describe('the emails themselves', () => {
  it('sends the bakery and the customer one email each', async () => {
    const { status, body } = await run()

    expect(status).toBe(200)
    expect(body).toEqual({ ok: true, orderNumber: ORDER_NUMBER })
    expect(sendMail).toHaveBeenCalledTimes(2)

    const [bakery, customer] = sent()
    expect(bakery.to).toBe('orders@example.com')
    expect(bakery.replyTo).toBe('jess@example.com')
    expect(bakery.subject).toBe(`New Pickup Order — Jess — ${ORDER_NUMBER}`)
    expect(customer.to).toBe('jess@example.com')
    expect(customer.subject).toMatch(/confirmed/i)
  })

  it('prices the basket from the server quote, never from the request', async () => {
    // A browser claiming its own prices must change nothing.
    await run({ ...BODY, total: '$0.01', lines: [{ price: '$0.01' }] })

    const [bakery] = sent()
    expect(bakery.html).toContain('Total: $62.26')
    expect(bakery.html).not.toContain('$0.01')

    expect(global.fetch).toHaveBeenCalledWith(
      'https://wp.example.com/wp-json/lilloaves/v1/quote',
      expect.objectContaining({
        method: 'POST',
        headers: expect.objectContaining({ 'X-LL-Secret': 'shh-secret' }),
      }),
    )
  })

  it('lists each item as "Name × qty", with the chosen pack size', async () => {
    await run()

    const [bakery, customer] = sent()
    for (const html of [bakery.html, customer.html]) {
      expect(html).toContain('Blueberry Muffin &times; 2')
      expect(html).toContain('Chocolate Chip Cookies (Box of 6) &times; 1')
    }
  })

  it('gives the bakery the contact details and the collection slot', async () => {
    await run()

    const [bakery, customer] = sent()
    expect(bakery.html).toContain('jess@example.com')
    expect(bakery.html).toContain('714-555-0123')
    expect(bakery.html).toContain('Orange County Store')
    // Figma 314:263 spells the date "2 Aug, Sunday" - the server's own label
    // and weekday joined, never a date computed here.
    expect(bakery.html).toContain('9 Aug, Sunday')
    expect(bakery.html).toContain('2:00 PM - 2:30 PM')
    // The customer needs the collection details too, but not their own
    // contact table read back at them.
    expect(customer.html).toContain('9 Aug, Sunday')
    expect(customer.html).toContain('2:00 PM - 2:30 PM')
    expect(customer.html).not.toContain('714-555-0123')
  })

  it('greets the customer by first name in caps, per the design', async () => {
    await run({ ...BODY, contact: { ...BODY.contact, name: 'moona patel' } })

    const [, customer] = sent()
    expect(customer.html).toContain('Thank You, MOONA!')
    // Not the surname, and not the raw casing.
    expect(customer.html).not.toContain('Thank You, moona patel!')
  })

  it('puts the same order number on both emails and returns it to the caller', async () => {
    const { body } = await run()
    const [bakery, customer] = sent()

    expect(body.orderNumber).toBe(ORDER_NUMBER)
    expect(customer.html).toContain(`Order #: ${ORDER_NUMBER}`)
    expect(bakery.html).toContain(`Order #: ${ORDER_NUMBER}`)
  })

  it('embeds the three template images as CID attachments, not data: URIs', async () => {
    await run()

    const [bakery, customer] = sent()
    for (const message of [bakery, customer]) {
      expect(message.attachments.map((a) => a.cid).sort()).toEqual(['llflower', 'llmail', 'llwave'])
      // Gmail strips data: URIs; cid: is the one embedding it honours.
      expect(message.html).not.toContain('data:image')
      for (const attachment of message.attachments) {
        expect(attachment.content.length).toBeGreaterThan(0)
        expect(message.html).toContain(`cid:${attachment.cid}`)
      }
    }
  })

  it('declares dark mode so iOS Mail stops rewriting the palette itself', async () => {
    await run()

    for (const { html } of sent()) {
      // Without these two, iOS Mail invents its own colour changes and a pale
      // palette like this one washes out.
      expect(html).toContain('<meta name="color-scheme" content="light dark">')
      expect(html).toContain('<meta name="supported-color-schemes" content="light dark">')
      expect(html).toContain('color-scheme: light dark')
      expect(html).toContain('@media (prefers-color-scheme: dark)')
      // Outlook.com rewrites the DOM instead of honouring the media query.
      expect(html).toContain('[data-ogsc]')
      expect(html).toContain('[data-ogsb]')
    }
  })

  it('pairs every dark-mode hook with an inline light colour', async () => {
    await run()

    // The light palette has to stay inline so clients that drop <style> still
    // get it; the classes are only the hook the dark rules override. An
    // element with a class but no inline colour would be invisible in one of
    // the two schemes.
    const { html } = sent()[1]
    for (const cls of ['ll-ink', 'll-muted', 'll-soft', 'll-cream-card', 'll-rose-card', 'll-pill']) {
      const uses = [...html.matchAll(new RegExp(`class="[^"]*\\b${cls}\\b[^"]*"[^>]*`, 'g'))]
      expect(uses.length).toBeGreaterThan(0)
      for (const [tag] of uses) expect(tag).toMatch(/style="[^"]*(color|background-color):/)
    }
  })

  it('lays out fluidly, so a phone gets full-width panels without a media query', async () => {
    await run()

    for (const { html } of sent()) {
      // The regression this guards: a fixed `width="650"` shell and fixed
      // `width="291"` panel cells rendered at roughly half width on a real
      // phone, because stacking was left entirely to a media query that some
      // clients (the Gmail app on a non-Gmail account) never apply.
      // Strips every comment, which covers both the Outlook ghost tables
      // (where a fixed width is correct) and the notes explaining why.
      const rendered = html.replace(/<!--[\s\S]*?-->/g, '')
      const fixedWidths = [...rendered.matchAll(/width="(\d+)"/g)]
        .map(([, w]) => Number(w))
        .filter((w) => w > 320)
      expect(fixedWidths).toEqual([])

      expect(html).toContain('max-width:650px')
      // The two pickup panels wrap on their own because two 300px
      // inline-blocks cannot share less than 600px.
      expect(html).toContain('display:inline-block;width:100%;max-width:300px')
      // Outlook ignores inline-block and max-width, so it gets ghost tables.
      expect(html).toContain('<!--[if mso]>')
    }
  })

  it('keeps every word as real text, so it reads with images off', async () => {
    await run()

    const [, customer] = sent()
    for (const phrase of [
      'Pick Up Confirmed! See you soon.',
      'Pickup Details',
      'Pickup Dates',
      'Pickup Time',
      'Order Summary',
      'Before You Arrive*',
      'From our table to yours, one hand-shaped loaf at a time.',
    ]) {
      expect(customer.html).toContain(phrase)
    }
    // Only the two decorations and the envelope glyph are images.
    expect(customer.html.match(/<img/g)).toHaveLength(3)
  })

  it('escapes customer text so a name cannot inject markup into the inbox', async () => {
    await run({ ...BODY, contact: { ...BODY.contact, name: '<img src=x onerror=alert(1)>' } })

    const [bakery] = sent()
    expect(bakery.html).not.toContain('<img src=x')
    expect(bakery.html).toContain('&lt;img src=x')
    expect(escapeHtml(`a"b'c&d`)).toBe('a&quot;b&#39;c&amp;d')
  })

  it('reports a send failure rather than claiming the order landed', async () => {
    sendMail.mockRejectedValue(new Error('SMTP said no'))
    const { status, body } = await run()

    expect(status).toBe(502)
    expect(body.ok).toBe(false)
  })

  it('still lists a line whose name will not resolve, rather than dropping it', async () => {
    global.fetch = vi.fn(async (url) => {
      const target = String(url)
      if (target.includes('/lilloaves/v1/pickup')) return { ok: true, json: async () => PICKUP_CONFIG }
      if (target.includes('/lilloaves/v1/quote')) return { ok: true, json: async () => QUOTE }
      // Both name sources down.
      return { ok: false, json: async () => null }
    })

    const { status } = await run()

    expect(status).toBe(200)
    const [bakery] = sent()
    expect(bakery.html).toContain('Product #1 &times; 2')
    expect(bakery.html).toContain('Total: $62.26')
  })
})
