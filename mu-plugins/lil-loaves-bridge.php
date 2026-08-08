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
    return ll_bump('ll_' . $bucket . '_' . md5($client), $max);
}

/**
 * wp_cache_incr is atomic and this host runs a persistent Memcached object
 * cache (verified). get_transient/set_transient is a read-modify-write and
 * undercounts precisely under the concurrent bursts that matter. The transient
 * path is only a fallback for a host without a persistent cache.
 */
function ll_bump($key, $max) {
    $group = 'lilloaves';
    wp_cache_add($key, 0, $group, LL_QUOTE_WINDOW);
    $hits = wp_cache_incr($key, 1, $group);
    if (false === $hits) {
        $hits = (int) get_transient($key) + 1;
        set_transient($key, $hits, LL_QUOTE_WINDOW);
    }
    return $hits > $max;
}

/**
 * Named buckets, so a flood aimed at /quote can never exhaust the budget that
 * lets real customers complete checkout. Task 7 passes its own bucket name.
 */
function ll_global_throttled($bucket = 'quote') {
    return ll_bump('ll_global_' . $bucket, 300);   // ~30/s sustained, under the LB's limit
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
 * Storefront origin allowlist. `/quote` is public and, via ll_boot_cart(),
 * resumes whatever cart a forwarded session cookie points at, then empties
 * it. That's the intended shape for the real caller (a server-to-server
 * call from the Vercel proxy, which forwards no cookie), but nothing
 * stopped a same-origin caller from silently wiping a real customer's
 * in-progress cart, coupon and shipping choice. Same reasoning as Task 7's
 * handoff-endpoint check; the allowlist lives in an option so that check
 * can read the same list.
 *
 * Fails closed: no Origin and no Referer is untrusted, not "presumably a
 * trusted server-to-server call."
 */
function ll_origin_allowed(WP_REST_Request $request) {
    $source = $request->get_header('origin') ?: $request->get_header('referer');
    if (!$source) return false;

    $scheme = wp_parse_url($source, PHP_URL_SCHEME);
    $host   = wp_parse_url($source, PHP_URL_HOST);
    if (!$scheme || !$host) return false;
    $port   = wp_parse_url($source, PHP_URL_PORT);
    $origin = $scheme . '://' . $host . ($port ? ':' . $port : '');

    $allowed = array_filter(array_map('trim', explode(',', (string) get_option('ll_allowed_origins', ''))));
    return in_array($origin, $allowed, true);
}

/**
 * Prices a prospective cart without creating an order.
 */
function ll_quote(WP_REST_Request $request) {
    if (!ll_wc_ready()) {
        return new WP_REST_Response(['errors' => ['Store unavailable']], 503);
    }

    if (!ll_origin_allowed($request)) {
        return new WP_REST_Response(['errors' => ['Origin not allowed']], 403);
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
