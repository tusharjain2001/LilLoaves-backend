/**
 * The storefront origins allowed to call POST /api/notify.
 *
 * An explicit allowlist, not `cors()` with no arguments. This route sends
 * mail on demand, so leaving it callable from any page on the internet is an
 * open spam relay pointed at the bakery's own inbox. The storefront is the
 * only caller and it is a browser, so the Origin header is real and not
 * forgeable by a third-party page — which makes checking it worth something
 * here. (That is the opposite of the mu-plugin's /quote endpoint, which is
 * called server-to-server, sends no Origin, and uses a shared secret instead.
 * Don't harmonise the two.)
 *
 * Read at call time rather than at import, so a changed environment variable
 * takes effect on the next request without a rebuild.
 */
export function isAllowedOrigin(origin) {
  if (!origin) return false
  const allowed = (process.env.ALLOWED_ORIGINS ?? '')
    .split(',')
    .map((o) => o.trim().replace(/\/$/, ''))
    .filter(Boolean)
  return allowed.includes(origin.replace(/\/$/, ''))
}
