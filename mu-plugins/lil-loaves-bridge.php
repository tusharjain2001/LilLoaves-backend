<?php
/**
 * Plugin Name: Lil' Loaves Bridge
 * Description: Prices a cart for the React storefront and hands it to WooCommerce checkout.
 *
 * The React app sends product ids and quantities only. Every price, shipping
 * rate and discount is computed here from WooCommerce's own data, so a
 * tampered request cannot change what a customer is charged.
 *
 * This is a MUST-USE plugin: it loads on every request and cannot be disabled
 * from wp-admin. A fatal here takes the whole site down, so every entry point
 * guards on WooCommerce being available and returns rather than throwing.
 * Rollback is documented in the backend repo's README.
 */

define('LL_BRIDGE', '1');

const LL_QUOTE_WINDOW      = 10;  // seconds
const LL_QUOTE_MAX         = 20;  // quotes per window per client
const LL_COUPON_MAX        = 5;   // coupon attempts per window per client

add_action('rest_api_init', function () {
    if (!ll_wc_ready()) return;
    register_rest_route('lilloaves/v1', '/quote', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true', // public, like the Store API; it creates nothing
        'callback'            => 'll_quote',
    ]);
});

/** Never assume WooCommerce is loaded. A must-use plugin runs before it may be. */
function ll_wc_ready() {
    return function_exists('WC') && WC() && class_exists('WC_Cart');
}

/**
 * Best-effort throttle. This is defence in depth, not a security boundary:
 * the forwarded IP is only as trustworthy as the proxy that set it. The
 * global cap is what actually protects the origin from a distributed burst.
 */
function ll_throttled($client, $bucket, $max) {
    return ll_bump(ll_windowed_key('ll_' . $bucket . '_' . md5($client)), $max);
}

/**
 * wp_cache_incr is atomic and this host runs a persistent Memcached object
 * cache (verified). get_transient/set_transient is a read-modify-write and
 * undercounts precisely under the concurrent bursts that matter. The transient
 * path is only a fallback for a host without a persistent cache.
 */
function ll_bump($key, $max, $ttl = LL_QUOTE_WINDOW) {
    $group = 'lilloaves';
    wp_cache_add($key, 0, $group, $ttl);
    $hits = wp_cache_incr($key, 1, $group);
    if (false === $hits) {
        $hits = (int) get_transient($key) + 1;
        set_transient($key, $hits, $ttl);
    }
    return $hits > $max;
}

/**
 * Folds the current time window into the cache key rather than trusting the
 * backend to honour wp_cache's $expire. Verified directly on this host that
 * it does not: a value set with a 2-second TTL was still present, unevicted,
 * 3 seconds later — via both the Object Cache API and the Transients API
 * (which itself delegates to the object cache whenever one is present, so it
 * isn't an independent fallback here). Without this, a throttle counter
 * climbs forever and never comes back down, wedging a client — or, on the
 * global bucket, every client — behind a rate limit that never lifts. Worse
 * than no throttle at all on an endpoint that creates real orders.
 *
 * A new window number is simply a new, unrelated key, so correctness no
 * longer depends on the backend ever expiring anything; wp_cache_incr stays
 * atomic, which a read-then-write reset would not.
 *
 * ponytail: fixed window, not sliding — a client can send up to 2x the cap
 * clustered around a window boundary. Acceptable for a defence-in-depth
 * throttle (see ll_throttled's own comment above); upgrade to a sliding
 * window only if that boundary burst becomes an observed problem.
 *
 * Deliberately NOT used for ll_token_seen()'s idempotency check below: a
 * token incorrectly surviving past its window is safe (it just stays
 * correctly rejected as a duplicate), whereas a token incorrectly expiring
 * early would defeat the double-charge guard entirely. The two failure
 * directions aren't symmetric, so the fix that's right for throttling would
 * be wrong there — token_seen accepts this host's "never expires" behaviour
 * as a safe, if slightly wasteful, default instead.
 */
function ll_windowed_key($key) {
    return $key . '_w' . (int) floor(time() / LL_QUOTE_WINDOW);
}

/**
 * Named buckets, so a flood aimed at /quote can never exhaust the budget that
 * lets real customers complete checkout. Task 7 passes its own bucket name.
 */
function ll_global_throttled($bucket = 'quote') {
    return ll_bump(ll_windowed_key('ll_global_' . $bucket), 300);   // ~30/s sustained, under the LB's limit
}

/**
 * The Vercel proxy forwards the real client IP in X-LL-Client. Falls back to
 * REMOTE_ADDR, which behind the proxy is the proxy itself — in that case every
 * customer shares one bucket, which is why the global cap exists too.
 */
function ll_client_id(WP_REST_Request $request) {
    $forwarded = $request->get_header('x_ll_client');
    if ($forwarded) return sanitize_text_field($forwarded);
    return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : 'unknown';
}

/**
 * `/quote` is public and, via ll_boot_cart(), resumes whatever cart a
 * forwarded session cookie points at, then empties it. Origin/Referer
 * can't gate that here: the real caller is a server-to-server call from
 * the Vercel proxy (api/store.js), which is not a browser and sends
 * neither header — and both are trivially forgeable by a non-browser
 * client anyway, so checking them buys nothing on a machine-to-machine
 * endpoint. A shared secret is a real guarantee instead of a spoofable
 * one, and it's strictly stronger: it makes /quote unreachable from a
 * browser at all, which closes the session-wipe risk at the root rather
 * than mitigating it.
 *
 * Task 7's handoff endpoint is a genuine browser form POST, so Origin is
 * the right check *there* — do not "harmonise" the two.
 *
 * hash_equals(), not ===, so a wrong guess can't be timed byte-by-byte.
 */
function ll_secret_ok(WP_REST_Request $request) {
    $secret = (string) get_option('ll_bridge_secret', '');
    $given  = (string) $request->get_header('x_ll_secret');
    return $secret !== '' && $given !== '' && hash_equals($secret, $given);
}

/**
 * Prices a prospective cart without creating an order.
 */
function ll_quote(WP_REST_Request $request) {
    if (!ll_wc_ready()) {
        return new WP_REST_Response(['errors' => ['Store unavailable']], 503);
    }

    if (!ll_secret_ok($request)) {
        return new WP_REST_Response(['errors' => ['Forbidden']], 403);
    }

    $client = ll_client_id($request);
    if (ll_global_throttled() || ll_throttled($client, 'q', LL_QUOTE_MAX)) {
        return new WP_REST_Response(['errors' => ['Too many requests']], 429);
    }

    $items      = $request->get_param('items');
    $fulfilment = $request->get_param('fulfilment') === 'pickup' ? 'pickup' : 'delivery';
    $postcode   = sanitize_text_field((string) $request->get_param('postcode'));
    $coupon     = sanitize_text_field((string) $request->get_param('coupon'));
    $errors     = [];

    if (!is_array($items) || count($items) === 0) {
        return new WP_REST_Response(ll_empty_quote(), 200);
    }

    ll_boot_cart();
    WC()->cart->empty_cart();

    foreach ($items as $item) {
        $id  = absint($item['id'] ?? 0);
        $qty = max(1, absint($item['qty'] ?? 1));
        if (!$id) continue;

        $product = wc_get_product($id);
        if (!$product || $product->get_status() !== 'publish' || !$product->is_purchasable()) {
            $errors[] = 'One of your items is no longer available';
            continue;
        }
        if (!$product->is_in_stock()) {
            $errors[] = sprintf('%s is sold out', $product->get_name());
            continue;
        }
        // add_to_cart returns false for reasons is_in_stock() does not cover,
        // such as a managed stock quantity below the requested amount.
        if (!WC()->cart->add_to_cart($id, $qty)) {
            $errors[] = sprintf('We could not add %s in that quantity', $product->get_name());
        }
    }

    $errors = array_merge($errors, ll_apply_fulfilment($fulfilment, $postcode));

    if ($coupon !== '') {
        if (ll_throttled($client, 'c', LL_COUPON_MAX)) {
            $errors[] = 'Too many coupon attempts, please wait a moment';
        } elseif (!WC()->cart->apply_coupon($coupon)) {
            $errors[] = 'That coupon code is not valid';
        }
        // WooCommerce reports the reason via wc_add_notice; we deliberately
        // return one generic message so an attacker learns nothing from probing.
        wc_clear_notices();
    }

    WC()->cart->calculate_totals();

    $minimum  = (float) get_option('ll_delivery_minimum', 0);
    $subtotal = (float) WC()->cart->get_subtotal();
    if ($fulfilment === 'delivery' && $minimum > 0 && $subtotal < $minimum) {
        $errors[] = sprintf('Delivery orders have a %s minimum', strip_tags(wc_price($minimum)));
    }

    // line_subtotal is pre-discount and shares its basis with get_subtotal().
    // line_total is post-discount, so using it here would make the line items
    // visibly fail to sum to the Subtotal row whenever a coupon is applied.
    // The discount is shown on its own row; it must not also be baked into
    // the lines.
    $lines = [];
    foreach (WC()->cart->get_cart() as $cart_item) {
        $lines[] = [
            'id'    => (int) $cart_item['product_id'],
            'qty'   => (int) $cart_item['quantity'],
            'total' => ll_minor($cart_item['line_subtotal'] + $cart_item['line_subtotal_tax']),
            'unit'  => ll_minor($cart_item['data']->get_price()),
        ];
    }

    $response = [
        'lines'    => $lines,
        'subtotal' => ll_minor(WC()->cart->get_subtotal()),
        'delivery' => ll_minor(WC()->cart->get_shipping_total()),
        'discount' => ll_minor(WC()->cart->get_discount_total()),
        'tax'      => ll_minor(WC()->cart->get_total_tax()),
        'total'    => ll_minor((float) WC()->cart->get_total('edit')),
        'currency' => ll_currency(),
        'errors'   => array_values(array_unique($errors)),
    ];

    WC()->cart->empty_cart();

    // empty_cart() doesn't stop WooCommerce from persisting a session row on
    // shutdown for this guest — calculate_totals() above sets the "has
    // cookie" flag regardless of whether one was actually sent, which makes
    // WC think there's a session worth saving. destroy_session() clears that
    // flag (and drops anything already attached to this customer id) so no
    // row survives past this request. /quote prices a hypothetical cart; it
    // must not create state for anyone to come back to.
    WC()->session->destroy_session();

    return new WP_REST_Response($response, 200);
}

function ll_empty_quote() {
    return [
        'lines' => [], 'subtotal' => 0, 'delivery' => 0, 'discount' => 0,
        'tax' => 0, 'total' => 0, 'currency' => ll_currency(), 'errors' => [],
    ];
}

/** WooCommerce's cart, session and customer do not exist during a REST request. */
function ll_boot_cart() {
    // WooCommerce only include()s these two procedural function files —
    // defining things WC_Cart::add_to_cart() and apply_coupon() call
    // internally, like wc_get_cart_item_data_hash() and wc_clear_notices()
    // — when is_request('frontend') or is_rest_api_request() is true.
    // Neither is true on admin-post.php, where is_admin() is set before
    // wp-load.php even runs. /quote never hits this gap because a REST
    // request already satisfies is_rest_api_request(); the handoff would
    // otherwise fatal deep inside WC_Cart::add_to_cart() with "Call to
    // undefined function wc_get_cart_item_data_hash()". include_once is a
    // no-op if /quote's request already loaded these, so this changes
    // nothing there.
    if (!function_exists('wc_get_cart_item_data_hash')) {
        include_once WC_ABSPATH . 'includes/wc-cart-functions.php';
    }
    if (!function_exists('wc_clear_notices')) {
        include_once WC_ABSPATH . 'includes/wc-notice-functions.php';
    }

    if (null === WC()->session) WC()->initialize_session();
    if (null === WC()->customer) WC()->customer = new WC_Customer(0, true);
    if (null === WC()->cart) WC()->initialize_cart();
}

/**
 * Chooses the shipping method server-side and returns any errors.
 *
 * Two passes, and the order matters. WC_Shipping::get_packages() is a bare
 * getter — the package list is only populated by calculate_shipping(). Calling
 * it first (as revision 1 did) always saw an empty array, so the rate match
 * never fired and the customer's fulfilment choice was silently discarded.
 * Pass one populates the rates, we select from them, pass two applies the
 * selection.
 */
function ll_apply_fulfilment($fulfilment, $postcode) {
    $errors  = [];
    $country = WC()->countries->get_base_country();

    WC()->customer->set_shipping_country($country);
    WC()->customer->set_shipping_postcode($fulfilment === 'delivery' ? $postcode : '');
    WC()->customer->set_billing_postcode($fulfilment === 'delivery' ? $postcode : '');

    // A delivery quote with no postcode yet is the cart's normal starting
    // state — the customer hasn't typed one in. That is not "outside our
    // area"; it is "no estimate yet", so skip the zone match and never
    // produce the out-of-area error for it. A non-empty postcode that
    // matches no zone still falls through to the error below, unchanged.
    if ($fulfilment === 'delivery' && $postcode === '') {
        WC()->cart->calculate_shipping();
        WC()->session->set('chosen_shipping_methods', []);
        return $errors;
    }

    WC()->cart->calculate_shipping();               // pass one: populate

    $wanted = $fulfilment === 'pickup' ? 'local_pickup' : 'flat_rate';
    $match  = null;
    foreach (WC()->shipping()->get_packages() as $package) {
        foreach ($package['rates'] as $key => $rate) {
            if ($rate->get_method_id() === $wanted) { $match = $key; break 2; }
        }
    }

    if (null === $match) {
        // No rate for the requested method. For delivery this means the
        // postcode is outside the zone, which must be an error and never a
        // silent zero — revision 1 quoted free delivery here.
        WC()->session->set('chosen_shipping_methods', []);
        $errors[] = $fulfilment === 'delivery'
            ? 'We do not deliver to that postcode yet'
            : 'Pickup is not available at the moment';
        return $errors;
    }

    WC()->session->set('chosen_shipping_methods', [$match]);
    WC()->cart->calculate_shipping();               // pass two: apply

    return $errors;
}

/**
 * The same shape the Store API returns, so the client can pass it straight to
 * formatPrice. Symbol, position and separators are all configurable in
 * WooCommerce and are not derivable from the ISO code alone.
 */
function ll_currency() {
    $code     = get_woocommerce_currency();
    $symbol   = html_entity_decode(get_woocommerce_currency_symbol($code));
    $position = get_option('woocommerce_currency_pos', 'left');

    $prefix = in_array($position, ['left', 'left_space'], true)
        ? $symbol . ($position === 'left_space' ? ' ' : '')
        : '';
    $suffix = in_array($position, ['right', 'right_space'], true)
        ? ($position === 'right_space' ? ' ' : '') . $symbol
        : '';

    return [
        'currency_code'                => $code,
        'currency_symbol'              => $symbol,
        'currency_minor_unit'          => wc_get_price_decimals(),
        'currency_decimal_separator'   => wc_get_price_decimal_separator(),
        'currency_thousand_separator'  => wc_get_price_thousand_separator(),
        'currency_prefix'              => $prefix,
        'currency_suffix'              => $suffix,
    ];
}

/** WooCommerce works in decimal; the Store API and React work in minor units. */
function ll_minor($amount) {
    return (int) round(((float) $amount) * (10 ** wc_get_price_decimals()));
}

/* -------------------------------------------------------------------------
 * Pickup scheduling — a wp-admin settings screen for stores/hours/blackout
 * dates, a public read endpoint that turns that config into real upcoming
 * dates, and handoff-time validation so a client can never claim a slot the
 * store doesn't actually offer. Same trust principle as re-pricing in
 * /quote and ll_handoff below: the client's slot choice is a request, not
 * an instruction, and gets checked against what the server would generate
 * right now.
 * ---------------------------------------------------------------------- */

const LL_FULFILMENT_OPTION = 'll_fulfilment_stores';
const LL_FULFILMENT_PAGE   = 'll-fulfilment';

/** Weekday ints follow PHP's date('w'): 0 = Sunday ... 6 = Saturday, so a
 *  stored day list compares directly against date('w') with no lookup. */
function ll_weekday_labels() {
    return [0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat'];
}

/** Fills in every field a stored store might be missing, so the rest of this
 *  file never has to null-check them one at a time. */
function ll_store_defaults($store) {
    $store = is_array($store) ? $store : [];
    return array_merge([
        'name'         => '',
        'address'      => '',
        'days'         => [],
        'start'        => '',
        'end'          => '',
        'slot_minutes' => 30,
        'blackout'     => [],
        'weeks_ahead'  => 4,
    ], $store);
}

function ll_fulfilment_stores() {
    $stores = get_option(LL_FULFILMENT_OPTION, []);
    return is_array($stores) ? array_map('ll_store_defaults', $stores) : [];
}

/**
 * ponytail: id collisions across two stores whose names strip down to the
 * same slug aren't de-duplicated — acceptable for a single bakery with one
 * or two locations; revisit with a stored uuid if a client ever runs many
 * stores with near-identical names.
 */
function ll_store_id($name, $index) {
    $slug = sanitize_title((string) $name);
    return $slug !== '' ? $slug : 'store-' . $index;
}

/**
 * Matches a pickup_store value against either the generated id/slug or the
 * store's exact display name (case-insensitive). The name match exists
 * because the live storefront (Cart.jsx) currently sends the store's literal
 * name, not a slug — see the README for the frontend field contract this is
 * pinned against; a future frontend using the /pickup endpoint's own `id`
 * keeps working through the first check.
 */
function ll_find_store($value) {
    $value = trim((string) $value);
    if ($value === '') return null;

    $stores = ll_fulfilment_stores();
    foreach ($stores as $i => $store) {
        if (ll_store_id($store['name'], $i) === $value) return $store;
    }
    foreach ($stores as $store) {
        if ($store['name'] !== '' && strcasecmp(trim($store['name']), $value) === 0) return $store;
    }
    return null;
}

/**
 * Expands a store's weekly pattern into real calendar dates for the next
 * N weeks, skipping blackout dates. This is the only place dates get
 * computed — both the public endpoint and the handoff validator call it, so
 * they can never disagree with each other about what's on offer.
 */
function ll_store_upcoming_dates($store) {
    $days = array_map('intval', (array) ($store['days'] ?? []));
    if (!$days) return [];

    $weeks_ahead = max(1, min(52, (int) ($store['weeks_ahead'] ?? 4)));
    $blackout    = array_flip(array_filter((array) ($store['blackout'] ?? [])));

    $tz      = wp_timezone();
    $cursor  = new DateTime('today', $tz);
    $horizon = (clone $cursor)->modify('+' . ($weeks_ahead * 7 - 1) . ' days');

    $dates = [];
    while ($cursor <= $horizon) {
        $iso = $cursor->format('Y-m-d');
        if (in_array((int) $cursor->format('w'), $days, true) && !isset($blackout[$iso])) {
            $dates[] = [
                'date'    => $iso,
                'weekday' => $cursor->format('l'),
                'label'   => $cursor->format('j M'),
            ];
        }
        $cursor->modify('+1 day');
    }
    return $dates;
}

/**
 * Generates the fixed daily time slots from a store's start/end/length. The
 * same slots apply on every open day, so this is independent of
 * ll_store_upcoming_dates() above rather than one computed per date — no
 * per-slot capacity means there is nothing date-specific about a slot.
 */
function ll_store_slots($store) {
    $length = max(5, (int) ($store['slot_minutes'] ?? 30));
    $tz     = wp_timezone();
    $start  = DateTime::createFromFormat('H:i', (string) ($store['start'] ?? ''), $tz);
    $end    = DateTime::createFromFormat('H:i', (string) ($store['end'] ?? ''), $tz);
    if (!$start || !$end || $start >= $end) return [];

    $slots  = [];
    $cursor = $start;
    while (true) {
        $next = (clone $cursor)->modify('+' . $length . ' minutes');
        if ($next > $end) break;
        $slots[] = [
            'start' => $cursor->format('H:i'),
            'end'   => $next->format('H:i'),
            'label' => $cursor->format('g:i A') . ' - ' . $next->format('g:i A'),
        ];
        $cursor = $next;
    }
    return $slots;
}

/**
 * The handoff-time authority for "is this a slot the store would actually
 * offer right now" — reuses the exact functions the public /pickup endpoint
 * returns, so a slot that endpoint offered can never be rejected here, and
 * one it didn't offer can never sneak through here either. $store_id
 * accepts anything ll_find_store() accepts (slug or exact name).
 */
function ll_pickup_slot_valid($store_id, $date, $slot) {
    $store = ll_find_store($store_id);
    if (!$store) return false;

    $date_ok = false;
    foreach (ll_store_upcoming_dates($store) as $d) {
        if ($d['date'] === $date) { $date_ok = true; break; }
    }
    if (!$date_ok) return false;

    foreach (ll_store_slots($store) as $s) {
        if ($s['start'] . '-' . $s['end'] === $slot) return true;
    }
    return false;
}

/**
 * Public, read-only, no secret required (unlike /quote): a store's name,
 * address and generated slot list isn't sensitive, and the client needs to
 * be able to render it before it has ever formed a cart. Guarded on
 * ll_wc_ready() only so ll_minor()/ll_currency() below never run against a
 * WooCommerce that isn't there yet — same reasoning as /quote's guard.
 */
add_action('rest_api_init', function () {
    if (!ll_wc_ready()) return;
    register_rest_route('lilloaves/v1', '/pickup', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => 'll_pickup_config',
    ]);
});

function ll_pickup_config(WP_REST_Request $request) {
    if (!ll_wc_ready()) {
        return new WP_REST_Response(['errors' => ['Store unavailable']], 503);
    }

    $stores = [];
    foreach (ll_fulfilment_stores() as $i => $store) {
        if (trim($store['name']) === '') continue; // an unfilled "+ Add store" row isn't a real store yet
        $stores[] = [
            'id'           => ll_store_id($store['name'], $i),
            'name'         => $store['name'],
            'address'      => $store['address'],
            'slot_minutes' => (int) $store['slot_minutes'],
            'slots'        => ll_store_slots($store),
            'dates'        => ll_store_upcoming_dates($store),
        ];
    }

    return new WP_REST_Response([
        'stores'           => $stores,
        'delivery_minimum' => ll_minor((float) get_option('ll_delivery_minimum', 0)),
        'currency'         => ll_currency(),
    ], 200);
}

/* -------------------------------------------------------------------------
 * Fulfilment settings screen — WooCommerce > Fulfilment. Where the bakery
 * owner sets stores, collection days/hours, blackout dates and the
 * delivery minimum, all from wp-admin with no code. Hand-rolled POST
 * handling and HTML rather than the Settings API: the Settings API has no
 * concept of a repeatable group of fields ("+ Add store"), so building
 * that on top of it would be more code than this, not less.
 * ---------------------------------------------------------------------- */

add_action('admin_menu', function () {
    if (!ll_wc_ready()) return;
    add_submenu_page(
        'woocommerce',
        'Fulfilment',
        'Fulfilment',
        'manage_woocommerce',
        LL_FULFILMENT_PAGE,
        'll_render_fulfilment_page'
    );
});

/**
 * Handles the settings form before any HTML is sent, so a successful save
 * can redirect (POST/redirect/GET) instead of leaving a page refresh
 * resubmit the form — this must run on admin_init, not inside the page
 * callback itself, which renders too late to redirect cleanly.
 */
add_action('admin_init', function () {
    if (!isset($_POST['ll_fulfilment_nonce'])) return;
    if (!ll_wc_ready() || !current_user_can('manage_woocommerce')) return;
    if (!check_admin_referer('ll_save_fulfilment', 'll_fulfilment_nonce')) return;

    $stores = [];
    foreach ((array) ($_POST['stores'] ?? []) as $raw) {
        if (!empty($raw['remove'])) continue; // "Remove this store" — dropped on save, not soft-deleted
        $stores[] = ll_sanitize_store($raw);
    }
    if (isset($_POST['ll_add_store'])) {
        $stores[] = ll_store_defaults([]); // a blank block for the owner to fill in, saved now so a refresh doesn't lose it
    }

    update_option(LL_FULFILMENT_OPTION, $stores);
    update_option('ll_delivery_minimum', max(0, (float) ($_POST['ll_delivery_minimum'] ?? 0)));

    wp_safe_redirect(add_query_arg(['page' => LL_FULFILMENT_PAGE, 'updated' => '1'], admin_url('admin.php')));
    exit;
});

function ll_sanitize_store($raw) {
    $raw  = is_array($raw) ? $raw : [];
    $days = array_values(array_intersect(array_map('intval', (array) ($raw['days'] ?? [])), [0, 1, 2, 3, 4, 5, 6]));

    $blackout = [];
    foreach (preg_split('/[\r\n,]+/', (string) ($raw['blackout'] ?? '')) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        // createFromFormat is lenient about impossible dates (e.g. silently
        // rolls 2026-02-30 into March) unless checked with getLastErrors();
        // round-tripping the parsed date back to a string and comparing is
        // simpler and equally reliable here.
        $d = DateTime::createFromFormat('Y-m-d', $line);
        if ($d && $d->format('Y-m-d') === $line) $blackout[] = $line;
    }

    return [
        'name'         => sanitize_text_field((string) ($raw['name'] ?? '')),
        'address'      => sanitize_text_field((string) ($raw['address'] ?? '')),
        'days'         => $days,
        'start'        => ll_sanitize_time($raw['start'] ?? ''),
        'end'          => ll_sanitize_time($raw['end'] ?? ''),
        'slot_minutes' => max(5, min(240, (int) ($raw['slot_minutes'] ?? 30))),
        'blackout'     => $blackout,
        'weeks_ahead'  => max(1, min(52, (int) ($raw['weeks_ahead'] ?? 4))),
    ];
}

function ll_sanitize_time($value) {
    $value = (string) $value;
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : '';
}

function ll_render_fulfilment_page() {
    if (!ll_wc_ready() || !current_user_can('manage_woocommerce')) return;

    $stores = ll_fulfilment_stores();
    if (!$stores) $stores = [ll_store_defaults([])]; // never show a blank page — give the owner one block to fill in
    $minimum = (float) get_option('ll_delivery_minimum', 0);
    $labels  = ll_weekday_labels();
    ?>
    <div class="wrap">
        <h1>Fulfilment</h1>
        <?php if (isset($_GET['updated'])) : ?>
            <div class="notice notice-success is-dismissible"><p>Saved.</p></div>
        <?php endif; ?>
        <p>Set up where and when customers can pick up their order, and the minimum order size for delivery.</p>

        <form method="post">
            <?php wp_nonce_field('ll_save_fulfilment', 'll_fulfilment_nonce'); ?>
            <?php submit_button('Save changes'); // also here, not just at the bottom, so pressing Enter in a text field saves rather than triggering "+ Add store" below ?>

            <?php foreach ($stores as $i => $store) : ?>
                <h2 style="margin-top:2em;"><?php echo $store['name'] !== '' ? esc_html($store['name']) : 'New store'; ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="ll-name-<?php echo (int) $i; ?>">Store name</label></th>
                        <td><input type="text" id="ll-name-<?php echo (int) $i; ?>" name="stores[<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr($store['name']); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="ll-address-<?php echo (int) $i; ?>">Address</label></th>
                        <td><input type="text" id="ll-address-<?php echo (int) $i; ?>" name="stores[<?php echo (int) $i; ?>][address]" value="<?php echo esc_attr($store['address']); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>Collection days</th>
                        <td>
                            <?php foreach ($labels as $num => $label) : ?>
                                <label style="margin-right:12px; display:inline-block;">
                                    <input type="checkbox" name="stores[<?php echo (int) $i; ?>][days][]" value="<?php echo (int) $num; ?>" <?php checked(in_array($num, $store['days'], true)); ?>>
                                    <?php echo esc_html($label); ?>
                                </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ll-start-<?php echo (int) $i; ?>">Collection hours</label></th>
                        <td>
                            From <input type="time" id="ll-start-<?php echo (int) $i; ?>" name="stores[<?php echo (int) $i; ?>][start]" value="<?php echo esc_attr($store['start']); ?>">
                            to <input type="time" name="stores[<?php echo (int) $i; ?>][end]" value="<?php echo esc_attr($store['end']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ll-slot-<?php echo (int) $i; ?>">Slot length</label></th>
                        <td><input type="number" id="ll-slot-<?php echo (int) $i; ?>" name="stores[<?php echo (int) $i; ?>][slot_minutes]" value="<?php echo esc_attr($store['slot_minutes']); ?>" min="5" max="240" step="5" class="small-text"> minutes</td>
                    </tr>
                    <tr>
                        <th><label for="ll-blackout-<?php echo (int) $i; ?>">Blackout dates</label></th>
                        <td>
                            <textarea id="ll-blackout-<?php echo (int) $i; ?>" name="stores[<?php echo (int) $i; ?>][blackout]" rows="3" class="regular-text" placeholder="2026-12-25, 2026-12-26"><?php echo esc_textarea(implode(', ', $store['blackout'])); ?></textarea>
                            <p class="description">One date per line or comma-separated, format YYYY-MM-DD. No pickups are offered on these dates.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ll-weeks-<?php echo (int) $i; ?>">Show the next</label></th>
                        <td><input type="number" id="ll-weeks-<?php echo (int) $i; ?>" name="stores[<?php echo (int) $i; ?>][weeks_ahead]" value="<?php echo esc_attr($store['weeks_ahead']); ?>" min="1" max="52" class="small-text"> weeks</td>
                    </tr>
                    <tr>
                        <th></th>
                        <td><label><input type="checkbox" name="stores[<?php echo (int) $i; ?>][remove]" value="1"> Remove this store</label></td>
                    </tr>
                </table>
            <?php endforeach; ?>

            <p><button type="submit" name="ll_add_store" value="1" class="button">+ Add store</button></p>

            <h2>Delivery</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="ll-delivery-minimum">Minimum order for delivery</label></th>
                    <td>
                        <?php echo esc_html(get_woocommerce_currency_symbol()); ?>
                        <input type="number" id="ll-delivery-minimum" name="ll_delivery_minimum" value="<?php echo esc_attr($minimum); ?>" min="0" step="0.01" class="small-text">
                        <p class="description">Orders below this can't check out with delivery. Leave at 0 for no minimum.</p>
                    </td>
                </tr>
            </table>

            <?php submit_button('Save changes'); ?>
        </form>
    </div>
    <?php
}

/* -------------------------------------------------------------------------
 * Checkout handoff — turns the browser's cart into a real WooCommerce order.
 * Runs on admin_post_ll_handoff / admin_post_nopriv_ll_handoff, a genuine
 * top-level browser form POST (see Task 9's checkout.js), never an XHR — so
 * unlike /quote it is gated by Origin/Referer, not a shared secret. See the
 * README for why the two endpoints use different gates; don't harmonise them.
 * ---------------------------------------------------------------------- */

const LL_HANDOFF_MAX       = 10;    // handoff attempts per window per client
const LL_HANDOFF_TOKEN_TTL = 3600;  // seconds an idempotency token stays claimed

add_action('admin_post_ll_handoff', 'll_handoff');
add_action('admin_post_nopriv_ll_handoff', 'll_handoff');

/**
 * Storefronts allowed to submit the handoff, and the only source of truth
 * for where a rejected request is sent back to — never the incoming
 * Origin/Referer, or the failure path becomes an open redirect. First entry
 * doubles as the default redirect target for a request whose origin isn't
 * trustworthy enough to redirect back to (foreign or missing).
 */
function ll_storefront_origins() {
    $stored  = get_option('ll_storefront_origins', []);
    $origins = is_array($stored) && $stored
        ? $stored
        : ['https://lil-loaves.vercel.app', 'http://localhost:5173'];
    return array_map('untrailingslashit', $origins);
}

function ll_storefront_url() {
    $origins = ll_storefront_origins();
    return $origins[0];
}

/**
 * wp_safe_redirect() only follows this site's own host plus whatever is
 * registered here. The storefront lives on a different domain (Vercel), so
 * without this every ll_reject() below would silently land on /wp-admin/
 * instead of the storefront's /cart — a must-use plugin can't afford a
 * "safe" redirect that quietly isn't.
 */
add_filter('allowed_redirect_hosts', function ($hosts) {
    foreach (ll_storefront_origins() as $origin) {
        $host = wp_parse_url($origin, PHP_URL_HOST);
        if ($host) $hosts[] = $host;
    }
    return $hosts;
});

/** scheme://host[:port] only — enough to compare against the allowlist. */
function ll_origin_of($url) {
    $parts = wp_parse_url((string) $url);
    if (empty($parts['scheme']) || empty($parts['host'])) return '';
    $origin = $parts['scheme'] . '://' . $parts['host'];
    if (!empty($parts['port'])) $origin .= ':' . $parts['port'];
    return $origin;
}

/**
 * Fails closed: if neither header is present, this returns null. A naive
 * "if present and not allowed, reject" lets an absent header through — this
 * checks presence itself, not only mismatch. Returns the *matched allowlist
 * entry* (never the raw header) so a later rejection can send a local-dev
 * request back to localhost instead of always defaulting to production —
 * still safe, because the return value is always one of our own configured
 * origins, filtered by the header, never the header's value itself.
 */
function ll_matched_origin() {
    $candidate = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    if ($candidate === '') return null;
    $origin = ll_origin_of($candidate);
    return in_array($origin, ll_storefront_origins(), true) ? $origin : null;
}

function ll_origin_ok() {
    return ll_matched_origin() !== null;
}

/**
 * Never wp_die() — a bakery customer must never see a raw WordPress error
 * page. $base, when given, must be a value already validated against the
 * allowlist (see ll_matched_origin()) — never pass a raw header through
 * here, or this becomes an open redirect. Defaults to the first allowlisted
 * origin for rejections that happen before/without a validated origin to
 * hand back (the guard, and the origin check's own failure).
 */
function ll_reject($code, $base = null) {
    wp_safe_redirect(($base ?? ll_storefront_url()) . '/cart?error=' . rawurlencode($code));
    exit;
}

/**
 * The handoff is a direct browser navigation to this site, not a request
 * proxied through Vercel — REMOTE_ADDR is the real customer's IP here,
 * unlike /quote where it's Vercel's egress IP.
 */
function ll_client_id_raw() {
    return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : 'unknown';
}

/**
 * Claim-once check, built on the same atomic counter ll_bump already uses
 * for throttling: max=1 means the first call (hits=1) returns false — "not
 * seen yet" — and every later call on the same token (hits>=2) returns
 * true. The increment itself is the check, so there's no window between
 * "check" and "mark seen" for two racing requests to both land in.
 */
function ll_token_seen($token) {
    return ll_bump('ll_token_' . md5($token), 1, LL_HANDOFF_TOKEN_TTL);
}

function ll_post($key) {
    return isset($_POST[$key]) ? wp_unslash($_POST[$key]) : '';
}

function ll_post_field($key) {
    return sanitize_text_field((string) ll_post($key));
}

/**
 * Turns a browser cart into a real WooCommerce order. Step numbers match
 * the brief; each depends on the ones before it having already run.
 */
function ll_handoff() {
    // 1. Guard — never fatal even if WooCommerce isn't available. Reading
    // the allowlist option doesn't need WooCommerce, so this still goes
    // through the normal reject path rather than a bespoke one.
    if (!ll_wc_ready()) {
        ll_reject('unavailable');
    }

    // 2. Boot the cart. admin-post.php defines WP_ADMIN before wp-load.php
    // runs, so is_admin() is true for this entire request and WooCommerce
    // never auto-creates WC()->cart/session/customer the way it does on a
    // normal frontend request. ll_wc_ready() does not cover this — it only
    // checks that the WC_Cart class exists, not that the object was booted.
    ll_boot_cart();

    // 3. Origin check — fail closed. This is a genuine browser form POST
    // (unlike /quote's server-to-server call), so Origin/Referer is a real,
    // unforgeable-by-a-third-party signal here. See the README. $origin is
    // the matched *allowlist entry*, not the raw header — every later
    // ll_reject() below hands it back explicitly so a rejected localhost
    // dev request returns to localhost instead of always defaulting to
    // production. Still never an open redirect: $origin can only ever be
    // one of our own configured origins (see ll_matched_origin()).
    $origin = ll_matched_origin();
    if ($origin === null) {
        ll_reject('origin');
    }

    // 4. Throttle — its own global bucket ('handoff', not /quote's), so a
    // flood aimed at /quote can never spend the budget real customers need
    // to finish checkout.
    $client = ll_client_id_raw();
    if (ll_global_throttled('handoff') || ll_throttled($client, 'handoff', LL_HANDOFF_MAX)) {
        ll_reject('throttled', $origin);
    }

    // 5. Idempotency — mark the token seen before doing any work. Belt and
    // braces, not the primary safeguard against a doubled order: that's
    // empty_cart() in step 6 (see its comment). This still earns its keep —
    // it rejects a stale resubmission outright rather than silently
    // recomputing and re-redirecting to checkout, and it's the only thing
    // that would still work if WooCommerce's session storage ever stopped
    // being a single atomic whole-session write (see step 6).
    $token = ll_post_field('token');
    if ($token === '' || ll_token_seen($token)) {
        ll_reject('unavailable', $origin);
    }

    // 6. Empty the real, persistent session cart before adding anything.
    // This — not the token above — is what actually prevents a double-click
    // or a back-then-resubmit from doubling the order, and it holds for a
    // genuine concurrent race too, not just a sequential resubmit: verified
    // live by racing two real, separate PHP processes against the same
    // WooCommerce session with empty_cart()+add_to_cart(same items), both
    // with the same token and, separately, with different tokens. In both
    // cases the persisted cart landed on the single correct quantity, never
    // doubled. Root cause: WC_Session_Handler persists the entire session
    // as one atomic blob on save, not as incremental per-item writes — two
    // racing processes each compute the identical target cart in their own
    // memory (empty, then add the same items), and whichever save wins
    // simply overwrites with that same, correct state. There's no shared
    // mutable structure for the two adds to interleave into. See the
    // README for the full trace.
    WC()->cart->empty_cart();

    $items = json_decode((string) ll_post('items'), true);
    if (!is_array($items)) $items = [];

    // 7. Re-price every line from WooCommerce's own product data. Only id
    // and qty are ever read from the POST — nothing price-shaped a client
    // could tamper with is ever looked at, let alone trusted.
    foreach ($items as $item) {
        $id  = absint($item['id'] ?? 0);
        $qty = max(1, absint($item['qty'] ?? 1));
        if (!$id) continue;

        $product = wc_get_product($id);
        if (!$product || $product->get_status() !== 'publish' || !$product->is_purchasable() || !$product->is_in_stock()) {
            continue; // no response channel to explain why; it just won't be in the cart
        }
        WC()->cart->add_to_cart($id, $qty);
    }

    if (WC()->cart->is_empty()) {
        ll_reject('unavailable', $origin);
    }

    $fulfilment = ll_post_field('fulfilment') === 'pickup' ? 'pickup' : 'delivery';
    $postcode   = ll_post_field('postcode');

    // 8. Apply the coupon the client saw at quote time, same call /quote
    // uses. If it no longer validates, stop rather than silently charging
    // more than was quoted.
    $coupon = ll_post_field('coupon');
    if ($coupon !== '') {
        $applied = WC()->cart->apply_coupon($coupon);
        wc_clear_notices();
        if (!$applied) {
            ll_reject('coupon', $origin);
        }
    }

    // 9. Re-validate fulfilment with the same helper /quote uses. A blank
    // delivery postcode is fine mid-quote (the customer just hasn't typed
    // one in yet) but is fatal here — checkout is final, and
    // ll_apply_fulfilment's "no postcode yet" branch reports no error while
    // leaving no shipping method chosen, which must never reach checkout
    // unnoticed.
    if ($fulfilment === 'delivery' && $postcode === '') {
        ll_reject('out_of_area', $origin);
    }
    if (ll_apply_fulfilment($fulfilment, $postcode)) {
        ll_reject($fulfilment === 'delivery' ? 'out_of_area' : 'unavailable', $origin);
    }

    // 9b. Validate the pickup slot itself. ll_apply_fulfilment() above only
    // confirmed local_pickup is a valid shipping method for *some* store —
    // it has no idea whether this store/date/time combination is one the
    // bakery actually offers. Same principle as re-pricing every line: the
    // client's slot choice is a request, not an instruction. Checked with
    // the same functions the public /pickup endpoint uses to generate the
    // slot list in the first place, so nothing can drift between "what we
    // offered" and "what we'll accept".
    if ($fulfilment === 'pickup') {
        $pickup_store = ll_post_field('pickup_store');
        $pickup_date  = ll_post_field('pickup_date');
        $pickup_slot  = ll_post_field('pickup_slot');
        if (!ll_pickup_slot_valid($pickup_store, $pickup_date, $pickup_slot)) {
            ll_reject('bad_slot', $origin);
        }
        // Resolved once here and reused at step 12 below, so the order (and
        // the confirmation emails built from it) always show the store's
        // actual configured name — never whatever raw value the client sent
        // as pickup_store, which ll_find_store() deliberately also accepts
        // as a slug/id (see its own comment) and would otherwise leak
        // straight onto a customer-facing email looking like "orange-county-store".
        $pickup_store_name = ll_find_store($pickup_store)['name'] ?? $pickup_store;
    }

    WC()->cart->calculate_totals();

    // 10. Delivery minimum, same option /quote reads.
    $minimum  = (float) get_option('ll_delivery_minimum', 0);
    $subtotal = (float) WC()->cart->get_subtotal();
    if ($fulfilment === 'delivery' && $minimum > 0 && $subtotal < $minimum) {
        ll_reject('below_minimum', $origin);
    }

    // 11. Prefill billing/shipping, sanitised. ll_apply_fulfilment already
    // set the postcode as part of choosing a shipping rate; this fills in
    // the rest of the address and contact details.
    $first_name = ll_post_field('first_name');
    $last_name  = ll_post_field('last_name');
    $email      = sanitize_email((string) ll_post('email'));
    $phone      = ll_post_field('phone');

    WC()->customer->set_billing_first_name($first_name);
    WC()->customer->set_billing_last_name($last_name);
    WC()->customer->set_billing_email($email);
    WC()->customer->set_billing_phone($phone);
    WC()->customer->set_shipping_first_name($first_name);
    WC()->customer->set_shipping_last_name($last_name);

    if ($fulfilment === 'delivery') {
        $address_1 = ll_post_field('address_1');
        $address_2 = ll_post_field('address_2');
        $city      = ll_post_field('city');
        $state     = ll_post_field('state');

        WC()->customer->set_billing_address_1($address_1);
        WC()->customer->set_billing_address_2($address_2);
        WC()->customer->set_billing_city($city);
        WC()->customer->set_billing_state($state);
        WC()->customer->set_shipping_address_1($address_1);
        WC()->customer->set_shipping_address_2($address_2);
        WC()->customer->set_shipping_city($city);
        WC()->customer->set_shipping_state($state);
    }

    WC()->customer->save();

    // 12. Pickup store/date/slot, and the fact that this order came through
    // our handoff at all (plus which storefront origin it came from), ride
    // the session into the order — the hooks below copy this onto order
    // meta once the order actually exists. ll_from_handoff is what lets the
    // post-payment redirect filter (further down) tell "this order came
    // through us" apart from any order reaching WooCommerce checkout some
    // other way, which must keep WooCommerce's own default behaviour.
    WC()->session->set('ll_from_handoff', true);
    WC()->session->set('ll_origin', $origin);
    WC()->session->set('ll_pickup', $fulfilment === 'pickup' ? [
        'store' => $pickup_store_name,
        'date'  => $pickup_date,
        'slot'  => $pickup_slot,
    ] : null);

    // 13. Hand off to WooCommerce's own checkout.
    wp_safe_redirect(wc_get_checkout_url());
    exit;
}

/** Copies session handoff/pickup data onto the order once it exists. */
function ll_copy_handoff_meta_to_order($order) {
    if (!ll_wc_ready() || null === WC()->session) return;
    if (!WC()->session->get('ll_from_handoff')) return;

    $order->update_meta_data('_ll_from_handoff', '1');
    $order->update_meta_data('_ll_origin', (string) WC()->session->get('ll_origin', ''));

    $pickup = WC()->session->get('ll_pickup');
    if (is_array($pickup)) {
        $order->update_meta_data('_ll_pickup_store', $pickup['store'] ?? '');
        $order->update_meta_data('_ll_pickup_date', $pickup['date'] ?? '');
        $order->update_meta_data('_ll_pickup_slot', $pickup['slot'] ?? '');
    }
}

// This store's checkout page is the block/Store API checkout (verified live),
// whose order creation (StoreApi\Utilities\OrderController) never fires the
// classic woocommerce_checkout_create_order action at all — only classic
// shortcode checkout does. The Store API's own equivalent is
// woocommerce_store_api_checkout_order_processed, fired *after* the order is
// already saved, so it needs an explicit save() where the classic hook (order
// not yet saved; the caller saves it) doesn't. Both are registered so this
// keeps working if the checkout page type ever changes back.
add_action('woocommerce_checkout_create_order', 'll_copy_handoff_meta_to_order');
add_action('woocommerce_store_api_checkout_order_processed', function ($order) {
    ll_copy_handoff_meta_to_order($order);
    $order->save();
});

/**
 * Sends a customer back to the storefront's own confirmation page after
 * paying, instead of leaving them on WooCommerce's thank-you page (which
 * looks nothing like the bakery). Only for orders that came through our
 * handoff — order meta, not a guess — so a customer who somehow reaches
 * WooCommerce checkout by another route keeps WooCommerce's own behaviour.
 *
 * Verified live which hook actually fires here rather than assuming: the
 * classic `woocommerce_thankyou`/return-URL path is a template-rendering
 * hook that only runs on the classic shortcode checkout, which this store
 * doesn't use (same trap as the pickup-meta hook above). What both the
 * classic *and* block/Store API checkout genuinely share is
 * `WC_Order::get_checkout_order_received_url()` itself — confirmed by
 * reading WooCommerce core: the Store API's CheckoutTrait calls it directly
 * for a $0 order, and COD's classic `process_payment()` reaches it via
 * `get_return_url()`. `woocommerce_get_checkout_order_received_url` is the
 * filter applied *inside* that method, so it's hit either way. Confirmed
 * live: completing a real COD order returned
 * `.../order-confirmed?order=<n>` in `payment_result.redirect_url`, the
 * exact field the block checkout's JS navigates to.
 *
 * The redirect target is the order's own stored `_ll_origin` — the
 * allowlist entry that matched at handoff time, never a raw header — so a
 * local-dev order returns to localhost instead of production. Falls back to
 * the default allowlist entry only if that's somehow missing.
 */
add_filter('woocommerce_get_checkout_order_received_url', function ($url, $order) {
    if (!$order || !$order->get_meta('_ll_from_handoff')) return $url;
    $origin = (string) $order->get_meta('_ll_origin');
    $base   = $origin !== '' ? $origin : ll_storefront_url();
    return $base . '/order-confirmed?order=' . rawurlencode($order->get_order_number());
}, 10, 2);

/** Shows pickup store/date/slot on the admin single-order screen. */
add_action('woocommerce_admin_order_data_after_shipping_address', function ($order) {
    $store = $order->get_meta('_ll_pickup_store');
    if ($store === '') return; // not a pickup order, nothing to show
    echo '<p class="ll-pickup-meta"><strong>' . esc_html__('Pickup', 'lilloaves') . ':</strong><br>'
        . esc_html($store) . '<br>'
        . esc_html($order->get_meta('_ll_pickup_date')) . ' ' . esc_html($order->get_meta('_ll_pickup_slot'))
        . '</p>';
});

/* -------------------------------------------------------------------------
 * Branded confirmation emails — WooCommerce already sends both required
 * emails (customer + bakery) with zero code; this section only changes how
 * they look and adds the fulfilment-specific content the design calls for.
 *
 * Colour and the personalised heading use WooCommerce's own, already
 * wp-admin-editable email settings (WooCommerce > Settings > Emails) via
 * the standard filters it exposes for exactly this — set once via WP-CLI
 * (see the README) rather than a template override, so the bakery can
 * still retune them later from wp-admin without touching this file.
 *
 * Deliberately NOT a full email-header.php/email-footer.php template
 * override, even though that's the more common way to reskin WooCommerce
 * emails. Checked against this store's actual WooCommerce 11.0.0 source:
 * WC_Emails::email_header()/email_footer() are hooked with the default
 * accepted_args of 1, so the $email object (needed to reach the order for
 * a personalised "Thank You, {name}") never actually reaches those two
 * templates even though do_action() is called with it — only $email_heading
 * does. The heading filter below receives the order directly, no override
 * needed. A full template override would also have to reproduce WC's
 * email_improvements feature-flag branching (header image, RTL, font
 * option) to avoid regressing anything neither this store nor this brief
 * asked to change.
 *
 * No flexbox anywhere below — WooCommerce's own order table is already
 * built on tables for Outlook, and every custom block here follows suit.
 * No custom @font-face either — Ligema/Parkinsans/Pacifico do not load in
 * Gmail or Outlook, so naming them first in a font stack (system fonts as
 * the fallback that actually renders) costs nothing and is honest about
 * what a subscriber will really see; see the README for the colour/curve
 * choices that carry the brand instead.
 * ---------------------------------------------------------------------- */

/**
 * "Pick Up Confirmed! Thank You, {first name}!" / "Order Confirmed! Thank
 * You, {first name}!" — the two customer order-status emails this store's
 * Cash on Delivery gateway actually triggers (COD sets non-downloadable
 * orders to "processing" — verified in WC_Gateway_COD::process_payment).
 * customer_on_hold_order is filtered too in case that ever changes (e.g. a
 * downloadable product gets added, which flips COD's default status).
 */
add_filter('woocommerce_email_heading_customer_processing_order', 'll_customer_email_heading', 10, 2);
add_filter('woocommerce_email_heading_customer_on_hold_order', 'll_customer_email_heading', 10, 2);
add_filter('woocommerce_email_heading_customer_completed_order', 'll_customer_email_heading', 10, 2);

function ll_customer_email_heading($heading, $order) {
    if (!($order instanceof WC_Order)) return $heading; // WooCommerce only ever calls this filter with an order for order emails, but never trust a filter's arg shape blindly
    $verb = ll_order_is_pickup($order) ? 'Pick Up Confirmed' : 'Order Confirmed';
    $name = trim($order->get_billing_first_name());
    return $name !== '' ? sprintf('%s! Thank You, %s!', $verb, $name) : $verb . '!';
}

/** The bakery's own heading names who ordered and how they're collecting it, so it's scannable from an inbox list without opening the email. */
add_filter('woocommerce_email_heading_new_order', function ($heading, $order) {
    if (!($order instanceof WC_Order)) return $heading;
    $mode = ll_order_is_pickup($order) ? 'Pickup' : 'Delivery';
    $name = trim($order->get_formatted_billing_full_name());
    return $name !== '' ? sprintf('New %s Order \xe2\x80\x94 %s', $mode, $name) : sprintf('New %s Order', $mode);
}, 10, 2);

/**
 * Whether an order is pickup or delivery, read from the order's own
 * shipping line rather than our _ll_pickup_store meta — that meta only
 * exists on orders that came through ll_handoff, but this must also give a
 * sane answer for an order placed any other way (e.g. created directly in
 * wp-admin), so it reads the same signal ll_apply_fulfilment() chose at
 * checkout time: which shipping method actually ended up on the order.
 */
function ll_order_is_pickup($order) {
    foreach ($order->get_shipping_methods() as $item) {
        if ($item->get_method_id() === 'local_pickup') return true;
    }
    return false;
}

/**
 * Reformats the canonical values ll_pickup_slot_valid() checks against
 * (Y-m-d; HH:MM-HH:MM) into something a customer reads naturally. Falls
 * back to the raw value on anything that doesn't parse rather than hiding
 * it — an order this old-format-tolerant handoff already accepted should
 * still show *something*, never a blank field.
 */
function ll_format_pickup_date($date) {
    $d = DateTime::createFromFormat('Y-m-d', $date, wp_timezone());
    return ($d && $d->format('Y-m-d') === $date) ? $d->format('j M, l') : $date;
}

function ll_format_pickup_slot($slot) {
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)-([01]\d|2[0-3]):([0-5]\d)$/', $slot, $m)) return $slot;
    $tz = wp_timezone();
    $s  = DateTime::createFromFormat('H:i', $m[1] . ':' . $m[2], $tz);
    $e  = DateTime::createFromFormat('H:i', $m[3] . ':' . $m[4], $tz);
    return ($s && $e) ? $s->format('g:i A') . ' - ' . $e->format('g:i A') : $slot;
}

/**
 * Extra CSS layered on top of WooCommerce's own generated stylesheet via
 * the filter it already exposes for this. WC_Email::style_inline() runs the
 * combined result through Emogrifier before sending, so these rules end up
 * inlined on the actual elements exactly like WooCommerce's own — no
 * separate inlining step needed here. Only touches classes this file itself
 * emits (ll-*), never WooCommerce's own selectors, so a bakery owner who
 * later retunes the colours in WooCommerce > Settings > Emails keeps
 * working exactly as they would without this filter.
 */
add_filter('woocommerce_email_styles', function ($css) {
    return $css . '
        .ll-card { background: #f9f1db; border-radius: 16px; padding: 16px 20px; }
        .ll-card-title { font-family: Georgia, "Times New Roman", serif; text-transform: uppercase; letter-spacing: 0.5px; color: #57423d; font-size: 14px; margin: 0 0 8px; }
        .ll-pill { display: inline-block; background: #f4e4b7; border-radius: 16px; padding: 6px 16px; color: #57423d; font-weight: bold; font-size: 14px; }
        .ll-muted { color: #8a7368; font-size: 12px; }
    ';
});

/**
 * The one piece of content with no native WooCommerce equivalent: fulfilment
 * details (pickup slot / delivery note for the customer, a scannable
 * fulfilment+contact summary for the bakery). Hooked before the order table
 * — on every email template that has one, HTML and plain-text alike, see
 * WC's emails/email-order-details.php and emails/plain/email-order-details.php
 * — and restricted to the email ids this actually applies to, so it never
 * renders on e.g. a refund or cancellation notice where "Pick Up Confirmed"
 * would be actively wrong.
 */
add_action('woocommerce_email_before_order_table', 'll_email_fulfilment_block', 20, 4);

function ll_email_fulfilment_block($order, $sent_to_admin, $plain_text, $email) {
    if (!($order instanceof WC_Order)) return;
    $relevant = ['customer_processing_order', 'customer_on_hold_order', 'customer_completed_order', 'new_order'];
    if (!is_object($email) || !in_array($email->id, $relevant, true)) return;

    $pickup = ll_order_is_pickup($order);

    if ($sent_to_admin) {
        ll_email_admin_fulfilment_block($order, $pickup, $plain_text);
        return;
    }

    if ($pickup) {
        ll_email_pickup_block($order, $plain_text);
    } else {
        ll_email_delivery_block($order, $plain_text);
    }
}

/** What the bakery works from: fulfilment method, pickup slot if any, and how to reach the customer — all in one place, before they scroll to the line items. */
function ll_email_admin_fulfilment_block($order, $pickup, $plain_text) {
    $store = trim((string) $order->get_meta('_ll_pickup_store'));
    $date  = trim((string) $order->get_meta('_ll_pickup_date'));
    $slot  = trim((string) $order->get_meta('_ll_pickup_slot'));

    $contact_parts = array_filter([$order->get_billing_phone(), $order->get_billing_email()]);
    $contact       = implode(' \xc2\xb7 ', $contact_parts);

    if ($plain_text) {
        echo "Fulfilment: " . ($pickup ? 'Pickup' : 'Delivery') . "\n";
        if ($pickup && $store !== '') {
            echo 'Store: ' . $store . "\n";
            if ($date !== '') echo 'Date: ' . ll_format_pickup_date($date) . "\n";
            if ($slot !== '') echo 'Time: ' . ll_format_pickup_slot($slot) . "\n";
        }
        if ($contact !== '') echo "Contact: {$contact}\n";
        echo "\n";
        return;
    }
    ?>
    <table cellpadding="0" cellspacing="0" width="100%" role="presentation" style="margin-bottom:16px;">
        <tr><td class="ll-card">
            <p class="ll-card-title" style="margin:0 0 8px;"><?php echo $pickup ? 'Pickup order' : 'Delivery order'; ?></p>
            <?php if ($pickup && $store !== '') : ?>
                <p style="margin:0 0 4px;">
                    <strong><?php echo esc_html($store); ?></strong>
                    <?php if ($date !== '') : ?> &mdash; <?php echo esc_html(ll_format_pickup_date($date)); ?><?php endif; ?>
                    <?php if ($slot !== '') : ?> <?php echo esc_html(ll_format_pickup_slot($slot)); ?><?php endif; ?>
                </p>
            <?php endif; ?>
            <?php if ($contact !== '') : ?>
                <p class="ll-muted" style="margin:0;">Contact: <?php echo esc_html($contact); ?></p>
            <?php endif; ?>
        </td></tr>
    </table>
    <?php
}

/** Customer's pickup confirmation — store, date, slot, and the "bring your order number" reminder from the Figma reference. */
function ll_email_pickup_block($order, $plain_text) {
    $store = trim((string) $order->get_meta('_ll_pickup_store'));
    $date  = trim((string) $order->get_meta('_ll_pickup_date'));
    $slot  = trim((string) $order->get_meta('_ll_pickup_slot'));

    if ($plain_text) {
        echo "Pickup Details\n";
        if ($store !== '') echo $store . "\n";
        if ($date !== '') echo 'Date: ' . ll_format_pickup_date($date) . "\n";
        if ($slot !== '') echo 'Time: ' . ll_format_pickup_slot($slot) . "\n";
        echo "\nBefore you arrive: please bring your order confirmation or order number when collecting your order.\n\n";
        return;
    }
    ?>
    <table cellpadding="0" cellspacing="0" width="100%" role="presentation" style="margin-bottom:16px;">
        <tr><td class="ll-card" style="text-align:center;">
            <p class="ll-card-title">Pickup Details</p>
            <?php if ($store !== '') : ?><p style="margin:0 0 12px;font-weight:600;color:#57423d;"><?php echo esc_html($store); ?></p><?php endif; ?>
            <table cellpadding="0" cellspacing="0" role="presentation" style="margin:0 auto;">
                <tr>
                    <?php if ($date !== '') : ?><td style="padding:0 8px;"><span class="ll-pill"><?php echo esc_html(ll_format_pickup_date($date)); ?></span></td><?php endif; ?>
                    <?php if ($slot !== '') : ?><td style="padding:0 8px;"><span class="ll-pill"><?php echo esc_html(ll_format_pickup_slot($slot)); ?></span></td><?php endif; ?>
                </tr>
            </table>
        </td></tr>
    </table>
    <p class="ll-muted" style="text-align:center;">Before you arrive: please bring your order confirmation or order number when collecting your order.</p>
    <?php
}

/**
 * Customer's delivery confirmation — the address, and the "next delivery
 * run" framing the design spec settled on: delivery orders don't pick a
 * date in v1 (see the commerce-layer spec's "Delivery orders do not select
 * a date" decision), so this states that plainly rather than implying a
 * schedule that doesn't exist.
 */
function ll_email_delivery_block($order, $plain_text) {
    $address = $order->get_formatted_shipping_address();
    if (!$address) $address = $order->get_formatted_billing_address();

    if ($plain_text) {
        echo "Delivery Details\n";
        echo wp_strip_all_tags((string) $address) . "\n";
        echo "\nWe'll deliver on our next delivery run and be in touch if that changes.\n\n";
        return;
    }
    ?>
    <table cellpadding="0" cellspacing="0" width="100%" role="presentation" style="margin-bottom:16px;">
        <tr><td class="ll-card" style="text-align:center;">
            <p class="ll-card-title">Delivery Details</p>
            <address style="font-style:normal;margin:0;color:#57423d;"><?php echo wp_kses_post((string) $address); ?></address>
        </td></tr>
    </table>
    <p class="ll-muted" style="text-align:center;">We'll deliver on our next delivery run and be in touch if that changes.</p>
    <?php
}
