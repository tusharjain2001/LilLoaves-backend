/**
 * Lil' Loaves order-notification service.
 *
 * A small Express app deployed to its own Vercel project. It shares this repo
 * with the WordPress must-use plugin but nothing else: the plugin goes to
 * WordPress.com by scp, this goes to Vercel by git, and neither deploy touches
 * the other.
 *
 * One route that matters:
 *
 *   POST /api/notify   emails the bakery and the customer a placed pickup
 *                      order. See lib/notify.js for what it validates and
 *                      why it re-prices server-side.
 *
 * Environment (see .env.example):
 *   WP_STORE_URL, LL_BRIDGE_SECRET   to re-price the basket via the plugin
 *   GMAIL_USER, GMAIL_APP_PASSWORD   the sending mailbox
 *   ORDER_EMAIL                      where the bakery receives orders
 *   ALLOWED_ORIGINS                  comma-separated storefront origins
 */

import 'dotenv/config'
import express from 'express'
import cors from 'cors'
import { handleNotify } from './lib/notify.js'
import { isAllowedOrigin } from './lib/origins.js'

const app = express()

app.use(
  cors({
    origin(origin, callback) {
      // No callback error on rejection: an error here surfaces as a 500.
      // Refusing the CORS headers is enough - the browser blocks the read,
      // and the guard inside the route below refuses to act on it anyway.
      callback(null, isAllowedOrigin(origin))
    },
    methods: ['POST'],
  }),
)
app.use(express.json({ limit: '32kb' }))

app.post('/api/notify', async (req, res) => {
  res.setHeader('Cache-Control', 'no-store')

  // CORS alone only stops the browser reading the *response*; the request
  // itself still arrives, and a non-browser client never asked permission at
  // all. Since this one sends mail, it fails closed on anything that isn't a
  // recognised storefront origin.
  if (!isAllowedOrigin(req.headers.origin)) {
    return res.status(403).json({ ok: false, error: 'Forbidden' })
  }

  const { status, body } = await handleNotify(req.body)
  return res.status(status).json(body)
})

app.get('/health', (_req, res) => res.json({ status: 'ok' }))

// Vercel imports the app and drives it itself; only a local run listens.
if (process.env.NODE_ENV !== 'production' || process.env.LOCAL) {
  const PORT = process.env.PORT || 4100
  app.listen(PORT, () => console.log(`Lil' Loaves order mail running on port ${PORT}`))
}

export default app
