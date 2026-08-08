# LilLoaves backend

WordPress.com backend for the Lil' Loaves bakery. Holds server-side glue
between the React storefront and WooCommerce — currently a single
must-use plugin that prices carts.

## What's here

- `mu-plugins/lil-loaves-bridge.php` — must-use plugin. Registers
  `POST /wp-json/lilloaves/v1/quote`, which prices a cart (items, shipping,
  coupon) using WooCommerce's own product/shipping/coupon data, so a
  tampered client request can never change what a customer is charged. It
  creates no order, and explicitly destroys the WooCommerce session it used
  before returning, so no session row survives a quote either (see below —
  `calculate_totals()` will otherwise persist one regardless of whether a
  cookie was ever sent).
- `.env` (gitignored) — WordPress.com site URL, SSH/SFTP access, WooCommerce
  object IDs. Copy `.env.example` to `.env` and fill in locally; never commit
  real values.

## Deploy

This is a WordPress.com Business/Commerce site — SSH gives WP-CLI, SFTP
gives file transfer, and both are exposed under the same `lilloaves-wp` host
alias (configured in `~/.ssh/config`). Must-use plugins load automatically
from `wp-content/mu-plugins/`; there's no activation step and no way to
disable one from `wp-admin`.

```bash
cd mu-plugins
scp lil-loaves-bridge.php lilloaves-wp:/srv/htdocs/wp-content/mu-plugins/
```

### Verify it loaded

```bash
ssh lilloaves-wp 'wp eval "echo defined(\"LL_BRIDGE\") ? \"loaded\" : \"NOT loaded\";"'
```

Expect `loaded`. Then load the storefront and `wp-admin` in a browser (or
`curl -o /dev/null -w '%{http_code}\n'` both URLs) — a fatal in a must-use
plugin takes the whole site down and shows up as a 500 on every page, since
it loads before anything else on every request.

WP-CLI commands that touch WooCommerce data need the store's admin user:
`wp --user=<admin-id> wc ...`. Plain `wp eval` / `wp eval-file` don't need
`--user`.

## Rollback

There is no admin toggle for a must-use plugin — deleting the file is the
toggle. This takes effect immediately, on the next request, no cache to
clear:

```bash
ssh lilloaves-wp 'rm /srv/htdocs/wp-content/mu-plugins/lil-loaves-bridge.php'
```

Confirm with the same `wp eval` check above (expect `NOT loaded`), then load
the storefront to confirm it recovered.

## `POST /wp-json/lilloaves/v1/quote`

Not actually public despite the permissive `permission_callback` — it's
gated by a shared secret (see below) and rate-limited (see further down).
Like the WooCommerce Store API, it creates no order, it only prices a
hypothetical cart.

**Shared secret, not Origin/Referer.** `ll_boot_cart()` resumes whatever
cart a forwarded session cookie points at, and `/quote` empties that cart
twice — so something has to gate who can call it. Origin/Referer doesn't
work here: the real caller is a **server-to-server** call from the Vercel
proxy (`api/store.js` in the frontend repo), which is not a browser and
sends neither header. It's also the wrong tool in principle — both headers
are trivially forgeable by any non-browser client, so checking them on a
machine-to-machine endpoint proves nothing. (An earlier revision of this
endpoint used an Origin allowlist; it's gone, replaced entirely by the
secret below — don't bring both back, two half-mechanisms are worse than
one that works.)

Every request must carry header `X-LL-Secret` matching the `ll_bridge_secret`
WordPress option, compared with `hash_equals()` (not `===`, so a wrong
guess can't be timed byte-by-byte). No match, or the header absent →
`403`. The secret lives in exactly two places: this WP option, and a
Vercel environment variable — **never in either git repo.** Rotate it with:

```bash
ssh lilloaves-wp "wp eval 'update_option(\"ll_bridge_secret\", bin2hex(random_bytes(32)), false);'"
```
then update the Vercel env var to match.

**This is deliberately different from Task 7's handoff endpoint**, which
checks Origin. That's correct there because the handoff *is* a genuine
browser form POST — the browser sends a real, unforgeable-by-a-third-party
Origin header, so checking it means something. `/quote` has no such
signal to check, hence the secret instead. Do not "harmonise" the two
mechanisms onto either endpoint.

**Request body:**

```json
{
  "items": [{ "id": 13, "qty": 2 }],
  "fulfilment": "delivery",
  "postcode": "92868",
  "coupon": "LOAF10"
}
```

- `items` — array of `{ id, qty }`. Missing/invalid ids are skipped with an
  error; an empty or missing array returns a zeroed quote (HTTP 200), not an
  error.
- `fulfilment` — `"pickup"` or `"delivery"`; anything else is treated as
  `"delivery"`.
- `postcode` — required for `"delivery"`; ignored (blanked) for `"pickup"`.
- `coupon` — optional coupon code.

**Response body** — all amounts are integers in **minor units** (cents):

```json
{
  "lines": [
    { "id": 13, "qty": 2, "total": 4226, "unit": 2113 }
  ],
  "subtotal": 4226,
  "delivery": 500,
  "discount": 0,
  "tax": 0,
  "total": 4726,
  "currency": {
    "currency_code": "USD",
    "currency_symbol": "$",
    "currency_minor_unit": 2,
    "currency_decimal_separator": ".",
    "currency_thousand_separator": ",",
    "currency_prefix": "$",
    "currency_suffix": ""
  },
  "errors": []
}
```

- `lines[].total` is pre-discount (matches `subtotal`'s basis) — the
  discount is shown on its own row, never baked into line items. So
  `Σ lines[].total === subtotal` always holds, coupon or not.
- `delivery` is 0 for pickup, the matched flat-rate cost for an in-zone
  delivery postcode, and — critically — an **error**, never a silent 0, for
  a postcode outside the delivery zone.
- `currency` is shaped exactly like the WooCommerce Store API's currency
  object, so the client can pass it straight to its existing `formatPrice`.
- `errors` is a flat array of user-facing strings (never a fatal or a
  WordPress notice dump). An empty array means the quote priced cleanly.

**Rate limits** (per 10-second window): 20 quotes and 5 coupon attempts per
client (identified by the `X-LL-Client` header the Vercel proxy sets, or
`REMOTE_ADDR` as a fallback), plus a global cap of 300 quotes shared across
all clients. Exceeding either returns HTTP 429 with `{ "errors": [...] }`.

## Store configuration this depends on

Set in `wp-admin` (not in code), and required for the endpoint's shipping
logic to behave correctly:

- A shipping zone restricted to the bakery's delivery postcodes, with a
  `flat_rate` method.
- `local_pickup` present on **both** that zone and the catch-all "Rest of
  the World" zone (zone 0). The plugin blanks the postcode for pickup
  requests, so pickup only resolves if some zone matches a blank postcode —
  that's zone 0. Without `local_pickup` there too, restricting the delivery
  area silently makes pickup unreachable.
