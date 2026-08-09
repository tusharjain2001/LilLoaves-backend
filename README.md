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
  cookie was ever sent). It also registers `admin_post(_nopriv)_ll_handoff`,
  a top-level browser form POST to `/wp-admin/admin-post.php` that turns
  that cart into a real WooCommerce order — see further down.
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
- `postcode` — used for `"delivery"`; ignored (blanked) for `"pickup"`. Not
  required: an empty or missing postcode on a delivery quote is the cart's
  normal starting state before the customer has typed one in, and returns
  `delivery: 0` with **no error** — it is not the same as "outside our
  area". Only a non-empty postcode that matches no shipping zone produces
  the out-of-area error.
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
- `delivery` is 0 in three different situations, only one of which is an
  error: pickup (always 0, no error); a delivery quote with no postcode
  entered yet (0, no error — the cart's normal starting state); and a
  delivery postcode outside the zone (0, **with** the out-of-area error —
  never a silent free shipping). Only a matched, in-zone postcode returns a
  non-zero `delivery`. The response carries no separate flag for "no
  estimate yet" vs "quoted at zero" — the client already has the postcode
  it sent, so it can tell these apart from its own request state without
  a round trip; add one only if that stops being true (e.g. a quote is
  ever rendered somewhere that didn't originate the request).
- `currency` is shaped exactly like the WooCommerce Store API's currency
  object, so the client can pass it straight to its existing `formatPrice`.
- `errors` is a flat array of user-facing strings (never a fatal or a
  WordPress notice dump). An empty array means the quote priced cleanly.

**Rate limits** (per 10-second window): 20 quotes and 5 coupon attempts per
client (identified by the `X-LL-Client` header the Vercel proxy sets, or
`REMOTE_ADDR` as a fallback), plus a global cap of 300 quotes shared across
all clients. Exceeding either returns HTTP 429 with `{ "errors": [...] }`.

## `POST /wp-admin/admin-post.php` (`action=ll_handoff`)

Turns a browser cart into a real WooCommerce order. Registered on both
`admin_post_ll_handoff` and `admin_post_nopriv_ll_handoff` so it works for
guest checkout. This is a genuine **top-level browser form POST** (built by
`checkout.js` in the frontend repo), not an XHR/fetch — a top-level
navigation is always first-party, which is why the plan uses a real form
submit here instead of a `fetch()`-built cart, and it survives Safari's and
Firefox's third-party cookie blocking that a cross-site `fetch()` would not.

**Origin/Referer, not a shared secret — deliberately the opposite of
`/quote`.** `/quote` is called server-to-server by the Vercel proxy, which
sends neither header, so Origin/Referer would check nothing there. The
handoff is the reverse: it's a real browser submitting a real form, so
Origin/Referer is a genuine, unforgeable-by-a-third-party signal, and a
shared secret would be the wrong tool (this endpoint has no server-to-server
caller to hand a secret to — the "caller" is a customer's browser). Do not
"harmonise" the two mechanisms onto either endpoint.

Every request must carry an `Origin` or `Referer` matching one of the
storefront origins in the `ll_storefront_origins` option (an array; defaults
to `https://lil-loaves.vercel.app` and `http://localhost:5173` if the option
is unset). **Fails closed**: if both headers are absent, the request is
rejected — a naive "if present and not allowed, reject" would still let a
header-stripping client through. Without this check, a third-party page
could force a victim's browser to submit the real checkout form, landing
them on a real order prefilled with an attacker's shipping address (CSRF).

**Every rejection redirects to `{storefront}/cart?error=<code>`, never
`wp_die()`** — a bakery customer must never see a raw WordPress error page.
The redirect target always comes from the **stored allowlist option**, never
from the raw incoming Origin/Referer *value*, or the failure path becomes an
open redirect. Once the Origin/Referer check itself has passed, later
rejections redirect back to whichever allowlist entry actually matched (so a
local dev rejection returns to `localhost`, not production) — still safe,
because that's a selection among our own configured origins, never the
header's raw value. The two rejections that can happen *before* a match
exists (WooCommerce unavailable; the Origin/Referer check's own failure)
fall back to the allowlist's first entry. Because the target is a different
domain than this site, it's also registered via the `allowed_redirect_hosts`
filter — `wp_safe_redirect()` silently falls back to `admin_url()` for any
host not on that list, which would otherwise turn every error case into a
confusing redirect to `/wp-admin/`.

Error codes: `origin`, `throttled`, `unavailable` (store not ready, spent
idempotency token, or a cart that ended up empty), `coupon`, `out_of_area`
(delivery outside the shipping zone, including a blank postcode — checkout
is final, unlike `/quote`'s live-typing UX where a blank postcode just means
"no estimate yet"), `below_minimum`.

**What actually prevents a doubled order — `empty_cart()`, not the
idempotency token.** The handler always empties the real WooCommerce session
cart before adding anything back. Verified live, not just reasoned about: two
genuinely concurrent PHP processes (not a sequential replay) racing
`empty_cart()` + `add_to_cart()` against the *same* WooCommerce session, with
the *same* items, converge on the correct single quantity every time —
tested both with the same idempotency token and, separately, with two
different tokens (i.e. with the token guard effectively absent). Neither
raced to a doubled cart. Root cause: `WC_Session_Handler` persists the whole
session as one atomic blob on save, not as incremental per-item writes. Two
racing requests each compute the same target cart in their own process
memory (empty, then add the same items) and whichever save lands last simply
overwrites with that same state — there's no shared mutable structure for
two adds to interleave into and inflate a quantity. This holds for the
double-click and back-then-resubmit cases the guard was built for, because
both requests always carry the *same* item list.

**Idempotency (`token`) is a real but secondary guard, kept as cheap defence
in depth.** The client generates it once per cart *state* (items,
fulfilment, postcode, coupon), not once per click — see Task 9 in the
frontend repo. It's marked seen atomically, before any work happens: the
first submission of a token succeeds, every later one with the same token is
rejected outright, before touching the cart at all. What it actually buys,
given `empty_cart()` already prevents doubling: it rejects a stale
resubmission outright rather than silently recomputing the same cart and
redirecting to checkout again, and it's the only thing that would still
protect against doubling if WooCommerce's session storage ever stopped being
a single atomic whole-session write. A token that outlives its intended
window (see the cache TTL note above — tokens are deliberately **not**
folded into `ll_windowed_key()`, unlike the throttle, precisely because an
idempotency check must err toward "still seen," never "forgot too early") is
therefore benign: worst case is one wasted, correctly-idempotent round trip
through checkout, never a doubled order.

**Rate limiting** uses the same per-client/global helpers as `/quote`, but
its own bucket names (`handoff`, both per-client and global). Sharing
`/quote`'s bucket would mean a flood aimed at the public `/quote` URL could
also block real customers from completing checkout, not just from seeing
quotes.

**Request body** — form fields, not JSON (this is an HTML form POST):

| Field | Value |
|---|---|
| `action` | `ll_handoff` |
| `items` | JSON string, `[{"id":13,"qty":2}]` — ids and quantities only |
| `fulfilment` | `delivery` or `pickup` |
| `token` | idempotency token, see above |
| `coupon` | coupon code, or empty |
| `email`, `phone`, `first_name`, `last_name` | contact |
| `address_1`, `address_2`, `city`, `state`, `postcode` | delivery only |
| `pickup_store`, `pickup_date`, `pickup_slot` | pickup only |

Every price-shaped value a client could send is ignored outright — only
`id`/`qty` are ever read from `items`, and every line is re-priced from
WooCommerce's own product data, the same as `/quote`. The coupon is
re-applied with `WC()->cart->apply_coupon()`; fulfilment is re-validated
with the same `ll_apply_fulfilment()` `/quote` uses, so a `pickup` claim can
never yield a delivery rate and an out-of-zone postcode can never yield free
delivery. The delivery minimum (`ll_delivery_minimum` option, same one
`/quote` reads) is enforced again here, server-side.

On success, billing/shipping are prefilled onto `WC()->customer` from the
sanitised POST, a pickup order's store/date/slot ride `WC()->session` onto
the order (copied to order meta `_ll_pickup_store`/`_ll_pickup_date`/
`_ll_pickup_slot`, and shown on the admin single-order screen), and the
response is `wp_safe_redirect(wc_get_checkout_url())` — WooCommerce's own
checkout, which is where payment (Cash on Delivery, currently the only
enabled gateway) actually happens and the order is actually created. The
handoff itself never creates an order; it only prepares the cart and session
that checkout uses.

**After payment, the customer returns to the storefront's own
`/order-confirmed?order=<order number>`, not WooCommerce's thank-you page.**
Two order-meta flags travel with the order for this: `_ll_from_handoff`
(marks it as ours) and `_ll_origin` (the storefront origin — the matched
allowlist entry, never a raw header — that submitted the handoff, so a
local-dev order returns to `localhost` and a production one to
`lil-loaves.vercel.app`). Both are copied from `WC()->session` onto the
order by the same hooks that copy pickup meta (see above). A
`woocommerce_get_checkout_order_received_url` filter checks for
`_ll_from_handoff` and, only when present, rewrites the URL; an order that
reached WooCommerce checkout some other way is untouched (verified: a plain
order with no handoff meta still gets WooCommerce's own
`/checkout/order-received/<id>/` URL).

This filter, not `woocommerce_thankyou`/`get_return_url`, is the correct
hook **verified against this store's actual checkout type**: the block/Store
API checkout's `CheckoutTrait` calls `$order->get_checkout_order_received_url()`
directly, and the classic COD gateway's `get_return_url()` reaches the same
method internally — `woocommerce_get_checkout_order_received_url` is the
filter applied *inside* that one shared method, so it fires either way.
Confirmed live: a completed COD order's `payment_result.redirect_url` (the
field the block checkout's JS actually navigates to) came back as
`https://lil-loaves.vercel.app/order-confirmed?order=<n>`.

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
- Cash on Delivery (`cod`) enabled under WooCommerce → Settings → Payments —
  the only gateway the handoff can currently complete an order through.
- `ll_storefront_origins` option (array) — the handoff's Origin/Referer
  allowlist and error-redirect target. Falls back in code to
  `https://lil-loaves.vercel.app` and `http://localhost:5173` if unset, so
  the endpoint behaves correctly even before this is explicitly set:
  ```bash
  ssh lilloaves-wp "wp option update ll_storefront_origins '[\"https://lil-loaves.vercel.app\",\"http://localhost:5173\"]' --format=json"
  ```
- `ll_delivery_minimum` option (float, minor-unit-free decimal, e.g. `25`
  for $25) — shared with `/quote`. Unset/`0` means no minimum, which is the
  current, deliberate default.
