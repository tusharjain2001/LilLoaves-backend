<?php
/**
 * One-off migration: splits every product's post_content — currently
 * "{tagline}{Ingredients: ...}{Allergens: ...}" all run into the single long
 * description, short description empty — into three separate places:
 *
 *   - short description (post_excerpt) = the tagline alone (what the menu
 *     card shows)
 *   - long description (post_content)  = the tagline alone too, so the
 *     WooCommerce product page still reads well standalone
 *   - "Ingredients" and "Allergens" custom product attributes (not
 *     taxonomy, not used for variations) — Ingredients always before
 *     Allergens
 *
 * The raw markup has no whitespace between the three parts — the boundary
 * is a closing/opening <p> tag, not whitespace — so this parses HTML
 * structure, not text. Lunch Box (15) is the one exception: its content IS
 * the tagline already, with no Ingredients/Allergens, and must end up with
 * no such attributes at all (never an empty one).
 *
 * Idempotent: safe to run twice. Each product is checked against its own
 * "already migrated" shape before anything is written; a second run prints
 * BEFORE == AFTER for every product and changes nothing. Stops rather than
 * guessing if any product's content doesn't match the expected pattern.
 *
 * Run with: wp eval-file scripts/restructure-product-content.php
 */

if (!defined('WP_CLI')) {
    fwrite(STDERR, "Run this via `wp eval-file`, not directly.\n");
    exit(1);
}

// The full catalogue as of 2026-08-12 (see CLAUDE.md's "Store facts"),
// including the draft (62) and Lunch Box (15). Hardcoded on purpose: this
// is a one-off content migration, not a general "process every product"
// tool, and a hardcoded list is what lets it stop on anything unexpected
// rather than silently reshaping a product nobody reviewed.
$product_ids  = [15, 60, 61, 62, 82, 84, 86, 88, 91, 94, 97];
$no_attrs_ids = [15]; // Lunch Box: tagline only, never gets Ingredients/Allergens attributes

$pattern = '#^<p>(.*?)</p>\s*<p>\s*<strong>\s*Ingredients:\s*</strong>\s*(.*?)</p>\s*<p>\s*<strong>\s*Allergens:\s*</strong>\s*(.*?)</p>\s*$#is';

function ll_migration_attr_summary($product) {
    $out = [];
    foreach ($product->get_attributes() as $attr) {
        $out[] = $attr->get_name() . '=' . implode('|', $attr->get_options());
    }
    return $out ? implode('; ', $out) : '(none)';
}

foreach ($product_ids as $id) {
    $post = get_post($id);
    if (!$post || $post->post_type !== 'product') {
        WP_CLI::error("Product $id: no such product post — stopping rather than guessing.");
    }

    $product = wc_get_product($id);
    if (!$product) {
        WP_CLI::error("Product $id: wc_get_product() failed — stopping rather than guessing.");
    }

    $before_content = $post->post_content;
    $before_excerpt = $post->post_excerpt;

    WP_CLI::log("=== Product $id: {$post->post_title} ===");
    WP_CLI::log('BEFORE description:       ' . $before_content);
    WP_CLI::log('BEFORE short_description: ' . $before_excerpt);
    WP_CLI::log('BEFORE attributes:        ' . ll_migration_attr_summary($product));

    $needs_attrs = !in_array($id, $no_attrs_ids, true);

    $has_ingredients = false;
    $has_allergens   = false;
    foreach ($product->get_attributes() as $attr) {
        if ($attr->get_id()) continue; // a taxonomy attribute (pa_pack-size) — never touched, never counted here
        $name = strtolower($attr->get_name());
        if ($name === 'ingredients') $has_ingredients = true;
        if ($name === 'allergens')   $has_allergens   = true;
    }

    $already_migrated = $needs_attrs
        ? ($has_ingredients && $has_allergens && strpos($before_content, 'Ingredients:') === false)
        : (strpos($before_content, 'Ingredients:') === false && $before_excerpt !== '');

    if ($already_migrated) {
        WP_CLI::log("SKIP: already migrated, no changes.\n");
        continue;
    }

    if ($id === 15) {
        if (strpos($before_content, 'Ingredients:') !== false) {
            WP_CLI::error('Product 15 unexpectedly contains an "Ingredients:" marker — stopping rather than guessing.');
        }
        $tagline     = trim(wp_strip_all_tags($before_content));
        $ingredients = null;
        $allergens   = null;
        if ($tagline === '') {
            WP_CLI::error('Product 15 has empty content — stopping rather than guessing.');
        }
    } else {
        if (!preg_match($pattern, trim($before_content), $m)) {
            WP_CLI::error("Product $id content does not match the expected tagline/Ingredients/Allergens pattern — stopping rather than guessing.\nContent was: $before_content");
        }
        $tagline     = trim($m[1]);
        $ingredients = trim($m[2]);
        $allergens   = trim($m[3]);
        if ($tagline === '' || $ingredients === '' || $allergens === '') {
            WP_CLI::error("Product $id: tagline, ingredients or allergens parsed empty — stopping rather than guessing.");
        }
    }

    $new_content = '<p>' . $tagline . '</p>';
    $product->set_description($new_content);
    $product->set_short_description($new_content);

    if ($needs_attrs) {
        $existing = $product->get_attributes(); // preserves pa_pack-size (and any other existing attribute) untouched

        // Drop any prior Ingredients/Allergens custom attribute before
        // re-adding — matters if a previous run got interrupted after
        // writing attributes but this run still has new text to apply, so
        // this never doubles up entries instead of replacing them.
        foreach ($existing as $key => $attr) {
            if (!$attr->get_id() && in_array(strtolower($attr->get_name()), ['ingredients', 'allergens'], true)) {
                unset($existing[$key]);
            }
        }

        $position = 0;
        foreach ($existing as $attr) {
            $position = max($position, $attr->get_position() + 1);
        }

        $ingredients_attr = new WC_Product_Attribute();
        $ingredients_attr->set_id(0);
        $ingredients_attr->set_name('Ingredients');
        $ingredients_attr->set_options([$ingredients]);
        $ingredients_attr->set_position($position++);
        $ingredients_attr->set_visible(true);
        $ingredients_attr->set_variation(false);

        $allergens_attr = new WC_Product_Attribute();
        $allergens_attr->set_id(0);
        $allergens_attr->set_name('Allergens');
        $allergens_attr->set_options([$allergens]);
        $allergens_attr->set_position($position++);
        $allergens_attr->set_visible(true);
        $allergens_attr->set_variation(false);

        $product->set_attributes(array_merge(array_values($existing), [$ingredients_attr, $allergens_attr]));
    }

    $product->save();

    // Re-fetch rather than trust what we intended to write.
    $after_post    = get_post($id);
    $after_product = wc_get_product($id);
    WP_CLI::log('AFTER description:        ' . $after_post->post_content);
    WP_CLI::log('AFTER short_description:  ' . $after_post->post_excerpt);
    WP_CLI::log('AFTER attributes:         ' . ll_migration_attr_summary($after_product));
    WP_CLI::log('');
}

WP_CLI::success('Done.');
