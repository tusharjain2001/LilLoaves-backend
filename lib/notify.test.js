import { handleNotify, normaliseItems, escapeHtml } from './notify.js'
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

const run = (body = BODY) => handleNotify(body, { makeTransport })

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
    expect(body).toEqual({ ok: true })
    expect(sendMail).toHaveBeenCalledTimes(2)

    const [bakery, customer] = sent()
    expect(bakery.to).toBe('orders@example.com')
    expect(bakery.replyTo).toBe('jess@example.com')
    expect(bakery.subject).toBe('New Pickup Order — Jess')
    expect(customer.to).toBe('jess@example.com')
    expect(customer.subject).toMatch(/confirmed/i)
  })

  it('prices the basket from the server quote, never from the request', async () => {
    // A browser claiming its own prices must change nothing.
    await run({ ...BODY, total: '$0.01', lines: [{ price: '$0.01' }] })

    const [bakery] = sent()
    expect(bakery.html).toContain('$42.26')
    expect(bakery.html).toContain('$62.26')
    expect(bakery.html).not.toContain('$0.01')

    expect(global.fetch).toHaveBeenCalledWith(
      'https://wp.example.com/wp-json/lilloaves/v1/quote',
      expect.objectContaining({
        method: 'POST',
        headers: expect.objectContaining({ 'X-LL-Secret': 'shh-secret' }),
      }),
    )
  })

  it('names items from the catalogue, including the chosen pack size', async () => {
    await run()

    const [bakery] = sent()
    expect(bakery.html).toContain('Blueberry Muffin')
    expect(bakery.html).toContain('Chocolate Chip Cookies')
    expect(bakery.html).toContain('Box of 6')
  })

  it('gives the bakery the contact details and the collection slot', async () => {
    await run()

    const [bakery, customer] = sent()
    expect(bakery.html).toContain('jess@example.com')
    expect(bakery.html).toContain('714-555-0123')
    expect(bakery.html).toContain('Orange County Store')
    expect(bakery.html).toContain('9 Aug')
    expect(bakery.html).toContain('2:00 PM - 2:30 PM')
    // The customer needs the collection details too, but not their own
    // contact table read back at them.
    expect(customer.html).toContain('2:00 PM - 2:30 PM')
    expect(customer.html).not.toContain('714-555-0123')
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
    expect(bakery.html).toContain('Product #1')
    expect(bakery.html).toContain('$42.26')
  })
})
