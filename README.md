# LilLoaves backend

WordPress.com backend for the Lil' Loaves bakery. Holds server-side glue
between the React storefront and WooCommerce — currently a single
must-use plugin that prices carts, schedules pickup, and hands checkout to
WooCommerce.

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
  that cart into a real WooCommerce order — see further down. Also adds a
  `WooCommerce > Fulfilment` settings screen, a public
  `GET /wp-json/lilloaves/v1/pickup` read endpoint, a public
  `GET /wp-json/lilloaves/v1/variations` read endpoint for pack-size prices,
  and branding for WooCommerce's own confirmation emails — see the matching
  sections below.
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
  "items": [
    { "id": 13, "qty": 2 },
    { "id": 88, "qty": 1, "variation_id": 90 }
  ],
  "fulfilment": "delivery",
  "postcode": "92868",
  "coupon": "LOAF10"
}
```

- `items` — array of `{ id, qty, variation_id? }`. `id` is always the
  product id — for a pack size (Muffins/Cookies/Crackers, all WooCommerce
  *variable* products sharing the `pa_pack-size` attribute) that's the
  shared **parent** id, never the variation's own id. `variation_id` is
  optional and selects which pack size; omit it (or send `0`) for a simple
  product (breads) and the cart line behaves exactly as it did before pack
  sizes existed. When present, it is validated (`ll_validate_variation()`)
  to genuinely belong to `id` and be purchasable —
  `wc_get_product($variation_id)` doesn't itself check that a variation and
  a parent id were sent together honestly, so a mismatched pair (e.g. `id`
  from one product, `variation_id` from another) is rejected with the same
  "no longer available" error as a bad `id`, never silently priced from
  whichever product the variation actually belongs to. Missing/invalid ids
  are skipped with an error; an empty or missing array returns a zeroed
  quote (HTTP 200), not an error.
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
    { "id": 13, "variation_id": 0, "qty": 2, "total": 4226, "unit": 2113 },
    { "id": 88, "variation_id": 89, "qty": 1, "total": 500, "unit": 500 },
    { "id": 88, "variation_id": 90, "qty": 1, "total": 2000, "unit": 2000 }
  ],
  "subtotal": 6726,
  "delivery": 500,
  "discount": 0,
  "tax": 0,
  "total": 7226,
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
- **Line matching key is `(id, variation_id)`, not `id` alone.** `id` is
  the parent product id and stays the same across pack sizes, so `id` alone
  cannot distinguish "1 × Single Cookie" from "1 × Box of 6" of the same
  Choco Chip Cookies product — the example above shows exactly that case:
  two lines, same `id: 88`, different `variation_id`, each independently and
  correctly priced (`500` and `2000`), summing to the right subtotal.
  `variation_id` is `0` for a simple product (bread), so `(id, 0)` is still
  a stable, unique key for those lines exactly as before pack sizes existed
  — this is not a breaking change to the existing contract, only an added
  field. WooCommerce itself is the reason this falls out for free: it keys
  each cart line by a hash that folds in `variation_id`, so two pack sizes
  of one parent are already two separate entries in `WC()->cart->get_cart()`
  with their own correct `line_subtotal` — nothing had to be de-duplicated
  or merged here. The client should key its own cart lines by `(id,
  variation_id)` the same way when matching a quote line back to a cart
  line, rather than by `id` and a separately-tracked "selected options"
  value.
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

## `GET /wp-json/lilloaves/v1/variations`

Per-variation prices for every WooCommerce **variable** product (Muffins,
Cookies, Crackers — all sharing the `pa_pack-size` attribute), in one
request. The Store API's own product list (`GET /wc/store/v1/products`)
returns each variable product's `attributes[].terms` and `variations[]`
(id + attribute slug) plus a `prices.price_range` min/max, but **no
per-variation price** — verified live — and a variation isn't individually
fetchable either (`?include=<variation_id>` returns empty). Without this
endpoint there is no way to show "Single Cookie $5.00" next to "Box of 6
$20.00", or to swap the displayed price when a customer picks one, short of
one request per product — which is exactly the per-page traffic
multiplication the Vercel proxy (`api/store.js`, frontend repo) exists to
collapse into roughly one upstream request per minute.

Modelled on `GET /pickup` below: **public, no `X-LL-Secret` required** (a
price is already public on the product page, so there's nothing here a
shared secret would protect), read-only, creates nothing, and returns
amounts in minor units with the same `currency` object shape as `/quote`
and `/pickup`, so the client can pass it straight to its existing
`formatPrice`. No request parameters.

```json
{
  "products": {
    "88": [
      {
        "id": 89,
        "name": "Single Cookie",
        "slug": "single-cookie",
        "price": 500,
        "in_stock": true,
        "purchasable": true
      },
      {
        "id": 90,
        "name": "Box of 6",
        "slug": "box-of-6",
        "price": 2000,
        "in_stock": true,
        "purchasable": true
      }
    ]
  },
  "currency": {
    "currency_code": "USD",
    "currency_symbol": "$",
    "currency_minor_unit": 2,
    "currency_decimal_separator": ".",
    "currency_thousand_separator": ",",
    "currency_prefix": "$",
    "currency_suffix": ""
  }
}
```

- `products` is an **object keyed by parent product id** (as a string,
  e.g. `"88"`, matching the `id` the storefront already uses to identify
  that product elsewhere) — a lookup, not a list, so the client does
  `variationsById[product.id]` rather than a linear search. Only variable
  products with at least one resolvable variation appear as a key; a simple
  product (bread) is never in this object at all — check for the parent
  id's presence to decide whether a product has pack sizes.
- Each entry is every variation WooCommerce has for that parent, **including
  out-of-stock or currently non-purchasable ones** — same principle as an
  out-of-stock simple product (Japanese Milk Bread) still appearing on the
  storefront rather than vanishing. `in_stock`/`purchasable` are what the
  client renders a pill as disabled from; nothing is filtered out here.
- `id` is the **variation id** — this is the value a client sends back as
  `variation_id` to `/quote` and the handoff (see their own docs). `name`
  and `slug` are the pack-size term's own name/slug (e.g. `"Single Cookie"`
  / `"single-cookie"`), read from the `pa_pack-size` taxonomy term, not
  hardcoded — this endpoint takes whichever attribute a variation actually
  has, so a renamed attribute or a second one added later needs no code
  change here (see `ll_variation_term()`'s own comment).
- `price` is that variation's own current price (minor units) — sale price
  if one is active, same as `WC_Product::get_price()` everywhere else in
  this plugin.

WordPress.com's edge/CDN caches `GET` responses the same way it does for
`/pickup` (see that section's own note on this) — harmless here too, since
a pack-size price does not need per-second freshness, but worth knowing if
a change in wp-admin seems to "not take effect" immediately.

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
"no estimate yet"), `below_minimum`, `bad_slot` (pickup only — the
`pickup_store`/`pickup_date`/`pickup_slot` combination isn't one
`GET /wp-json/lilloaves/v1/pickup` would currently offer; see below).

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
| `items` | JSON string, `[{"id":13,"qty":2},{"id":88,"qty":1,"variation_id":90}]` — ids, quantities and (for a pack size) a variation id only |
| `fulfilment` | `delivery` or `pickup` |
| `token` | idempotency token, see above |
| `coupon` | coupon code, or empty |
| `email`, `phone`, `first_name`, `last_name` | contact |
| `address_1`, `address_2`, `city`, `state`, `postcode` | delivery only |
| `pickup_store`, `pickup_date`, `pickup_slot` | pickup only |

Every price-shaped value a client could send is ignored outright — only
`id`/`qty`/`variation_id` are ever read from `items`, and every line is
re-priced from WooCommerce's own product data, the same as `/quote`,
including the same `ll_validate_variation()` check that a pack size
genuinely belongs to its stated parent product before it's added — a
variation id is a selector, never a price. The coupon is
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
  current, deliberate default. Editable from `WooCommerce > Fulfilment`
  (see below), not just via WP-CLI.

## Pickup scheduling

### `WooCommerce > Fulfilment` (wp-admin screen)

Where a non-technical bakery owner sets everything below with no code —
`add_submenu_page('woocommerce', ...)`, hand-rolled POST handling (no
Settings API — it has no concept of a repeatable "+ Add store" group), one
option (`ll_fulfilment_stores`, an array) plus the existing
`ll_delivery_minimum`. Per store:

| Field | Notes |
|---|---|
| Store name | Also becomes the public `id` (slugified) the REST endpoint and handoff use |
| Address | Free text, shown to the customer |
| Collection days | Checkboxes, Sun–Sat |
| Collection hours | `start`/`end`, `HH:MM` |
| Slot length | Minutes; slots are generated `start` → `end` in this increment |
| Blackout dates | Textarea, one per line or comma-separated, `YYYY-MM-DD`; invalid/impossible dates are silently dropped, not saved |
| Show the next N weeks | How far ahead `GET /pickup` generates dates |
| Remove this store | Checkbox; dropped from the array on save |

"+ Add store" appends a blank store block and saves immediately (so a page
refresh doesn't lose it) — same form, same POST/redirect/GET save handler
(`admin_init`, nonce `ll_save_fulfilment`, capability `manage_woocommerce`).
Delivery minimum lives on the same page since it's the same "how fulfilment
works" concern, previously only settable via WP-CLI.

### `GET /wp-json/lilloaves/v1/pickup`

Public, read-only, **no `X-LL-Secret` required** — unlike `/quote`, this
creates nothing and exposes only a store's name/address/hours, which isn't
sensitive. Generates real calendar dates and time slots server-side from
each store's weekly pattern, honouring blackout dates and the weeks-ahead
setting, so the client does zero date arithmetic.

```json
{
  "stores": [
    {
      "id": "orange-county-store",
      "name": "Orange County Store",
      "address": "1234 Example Ave, Orange County, CA",
      "slot_minutes": 30,
      "slots": [
        { "start": "14:00", "end": "14:30", "label": "2:00 PM - 2:30 PM" }
      ],
      "dates": [
        { "date": "2026-08-09", "weekday": "Sunday", "label": "9 Aug" }
      ]
    }
  ],
  "delivery_minimum": 0,
  "currency": { "currency_code": "USD", "...": "same shape as /quote's currency object" }
}
```

`slots` and `dates` are deliberately separate arrays, not one flat list of
combos — the same slots apply on every open day (no per-slot capacity, so
nothing is date-specific about a slot), and crossing them client-side is one
nested loop. `date` (`Y-m-d`) and `slot.start`/`slot.end` (`H:i`) are the
**canonical values a handoff POST must send back** as `pickup_date` and
`pickup_slot="{start}-{end}"` (e.g. `14:00-14:30`) — `label` fields are
display-only. A store row with a blank name (an unfilled "+ Add store"
block) is omitted from the response.

An unfortunate discovery while testing this live: WordPress.com's edge/CDN
caches this endpoint's `GET` responses (`Server-Timing: ...cache;desc=HIT`
observed on a second request within seconds). Harmless for how this data is
used — pickup slots don't need per-second freshness — but worth knowing if
a future change here seems to "not take effect" immediately; a cache-busting
query string (`?_=<timestamp>`) confirms the real current state.

### Handoff validation (`bad_slot`)

`ll_handoff()` now rejects a pickup order whose `pickup_store`/
`pickup_date`/`pickup_slot` isn't one the store would actually offer right
now, via `ll_pickup_slot_valid()` — the exact same functions
`GET /pickup` uses to generate its lists, so the two can never disagree.
Same trust principle as re-pricing every line item: the client's slot claim
is a request, not an instruction. `pickup_store` matches either the
generated `id` (slug) or the store's exact display name (case-insensitive) —
the name match exists because the live storefront currently sends the
store's literal name (`"Orange County Store"`), not a slug.

No per-slot capacity — deliberately out of scope (avoids a reservation race
at checkout), same as `/quote`.

## Branded confirmation emails

WooCommerce sends the customer and bakery emails with zero code — this only
changes how they look and adds fulfilment content, via three mechanisms
layered on top of stock WooCommerce, not a template override:

1. **Colour and personalised heading use WooCommerce's own, already
   wp-admin-editable email settings** (`WooCommerce > Settings > Emails`),
   set once via WP-CLI rather than hardcoded, so the bakery can retune them
   later without touching code:
   ```bash
   ssh lilloaves-wp "wp option update woocommerce_email_base_color '#57423d'"
   ssh lilloaves-wp "wp option update woocommerce_email_background_color '#fbfbf8'"
   ssh lilloaves-wp "wp option update woocommerce_email_body_background_color '#fffefb'"
   ssh lilloaves-wp "wp option update woocommerce_email_text_color '#57423d'"
   ssh lilloaves-wp "wp option update woocommerce_email_footer_text_color '#57423d'"
   ssh lilloaves-wp "wp option update woocommerce_email_from_name \"Lil' Loaves\""
   ssh lilloaves-wp "wp option update woocommerce_email_footer_text '🧡 The Lil'\'' Loaves Family — From our table to yours, one hand-shaped loaf at a time.'"
   ```
   The heading itself ("Pick Up Confirmed! Thank You, Manan!" /
   "Order Confirmed! Thank You, Manan!" / bakery-side "New Pickup Order —
   Manan Utsav") comes from `woocommerce_email_heading_{email_id}` filters
   in the plugin — WooCommerce's own, documented extension point, not a
   template override.
2. **`woocommerce_email_styles` filter** appends `.ll-card`/`.ll-pill`/
   `.ll-muted` CSS (scalloped card, pill badge, muted caption — the Figma
   reference's visual language) on top of WooCommerce's generated
   stylesheet. `WC_Email::style_inline()` runs the combined CSS through
   Emogrifier before sending, so these rules end up inlined exactly like
   WooCommerce's own — required for Outlook, which ignores `<style>` blocks.
   Fonts declare Ligema/Parkinsans/Georgia first and a system stack after —
   Gmail and Outlook strip custom `@font-face`, so only the fallback ever
   actually renders; this is honest about that rather than pretending
   otherwise.
3. **`woocommerce_email_before_order_table` action** — the one piece with no
   native WooCommerce equivalent — adds the fulfilment content itself,
   branching on `sent_to_admin` and on whether the order's shipping line is
   `local_pickup` (`ll_order_is_pickup()`, read from the order's own
   shipping item, not `_ll_pickup_store` meta, so it gives a sane answer for
   an order placed any way other than `ll_handoff` too):
   - **Customer, pickup**: store name, date, time slot as pill badges, "bring
     your order confirmation or order number" reminder.
   - **Customer, delivery**: the shipping address, and a note that it ships
     on the bakery's next delivery run (the commerce-layer spec's decision —
     delivery orders don't pick a date in v1).
   - **Bakery (`new_order`)**: fulfilment method, store/date/slot if pickup,
     and a `Contact: phone · email` line, all before the line-item table so
     it's the first thing staff see.
   Restricted to `customer_processing_order`, `customer_on_hold_order`,
   `customer_completed_order` and `new_order` — never refund/cancellation/
   failed notices, where "Pick Up Confirmed!" would be actively wrong.
   Handles both the HTML and plain-text templates (both fire the same hook).

Also fixed while wiring this up: Cash on Delivery's own instructions text
defaulted to "Pay with cash upon delivery," which reads as wrong on a pickup
order —
```bash
ssh lilloaves-wp "wp option update woocommerce_cod_settings '{\"enabled\":\"yes\",\"title\":\"Pay at pickup \/ on delivery\",\"instructions\":\"Pay in cash when you collect your order, or when it'\''s delivered.\"}' --format=json"
```

**Verified against this store's actual WooCommerce 11.0.0 source, not
assumed**: `WC_Emails::email_header()`/`email_footer()` are hooked with the
default `accepted_args` of `1`, so despite `do_action('woocommerce_email_header',
$email_heading, $email)` being called with two arguments, the `$email`
object never actually reaches `emails/email-header.php` — only
`$email_heading` does. A full template override would have been needed to
reach the order for personalisation; the heading filter above receives the
order directly instead, which is why this doesn't use one.

**Cash on Delivery order status**: COD's own `process_payment()` sets a
non-downloadable order to `processing`, not `on-hold` (verified: both real
test orders below landed on `processing`). `ll_order_is_pickup()` and the
heading filters are wired to `customer_on_hold_order` too, in case that ever
changes (e.g. a downloadable product gets added, which flips COD's default).

**Verified live**: placed one real pickup order (Sour Dough, `local_pickup`
$0, total $21.13) and one real delivery order (Danish Pastries, `flat_rate`
$5, total $28.00) via the actual Store API checkout endpoint (COD), using
the same cookie session a real `ll_handoff` redirect produces. Both orders'
`_new_order_email_sent` meta was `true` after checkout — WooCommerce's own
anti-duplicate guard, and proof the real admin email genuinely sent, not
just that its content renders. The customer email was independently
confirmed sent via a `wp_mail_succeeded` hook. Rendered HTML (post-Emogrifier,
the literal bytes handed to `wp_mail()`) saved for all four
pickup/delivery × customer/admin combinations; both test orders deleted
afterward. See the plan report for specifics and file locations.
