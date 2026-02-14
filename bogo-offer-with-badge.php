<?php

/**
 * Plugin Name: BOGO Offer with Badge
 * Description: 
 * Plugin URI: ibrahimmonir.com
 * Author URI: ibrahimmonir.com
 * Author: Ibrahim Monir
 * Version: 1.0.0
 * 
 */
// Bundle with BOGO


// =====================
// 1. Define BOGO Categories
// =====================
function get_bogo_categories() {
    return ['contrast-stitch', 'cotton-casual-full-sleeve-shirt', 'cotton-casual-half-sleeve-shirt', 'denim-shirt', 'flannel-shirt', 'kaftan-shirt', 'sweatshirt', 'turtle-neck', 'full-sleeves', ];
}

// =====================
// 2. Identify if cart item is BOGO eligible
// =====================
function is_bogo_item($cart_item) {
    $product = $cart_item['data'];
    $product_id = $product->get_parent_id() ?: $product->get_id();
    foreach (get_bogo_categories() as $slug) {
        if (has_term($slug, 'product_cat', $product_id)) {
            return true;
        }
    }
    return false;
}

// =====================
// 3. Apply BOGO pricing per category
// =====================
add_action('woocommerce_before_calculate_totals', 'category_based_bundle_pricing', 20, 1);
function category_based_bundle_pricing($cart) {

    if (is_admin() && !defined('DOING_AJAX')) return;
    if ($cart->is_empty()) return;

    $bundle_categories = [
        'contrast-stitch', 'cotton-casual-full-sleeve-shirt', 'cotton-casual-half-sleeve-shirt', 'denim-shirt', 'flannel-shirt', 'kaftan-shirt', 'sweatshirt', 'turtle-neck', 'full-sleeves'
    ];

    // Reset previous BOGO free flags
    foreach ($cart->get_cart() as $cart_item_key => $item) {
        if (isset($item['is_bogo_free'])) {
            unset($cart->cart_contents[$cart_item_key]['is_bogo_free']);
        }
    }

    // Collect items by category
    $grouped = [];
    foreach ($cart->get_cart() as $cart_item_key => $item) {
        $product = $item['data'];
        $product_id = $product->get_parent_id() ?: $product->get_id();

        foreach ($bundle_categories as $slug) {
            if (has_term($slug, 'product_cat', $product_id)) {
                $grouped[$slug][$cart_item_key] = $item;
                break;
            }
        }
    }

    // Apply bundle logic per category
    foreach ($grouped as $category => $items) {
        if (count($items) < 2) continue;

        $expanded = [];
        foreach ($items as $key => $item) {
            for ($i = 0; $i < $item['quantity']; $i++) {
                $expanded[] = [
                    'key'   => $key,
                    'price' => (float) $item['data']->get_regular_price(),
                ];
            }
        }

        if (count($expanded) < 2) continue;

        // Sort prices ASC (cheap first)
        usort($expanded, function ($a, $b) {
            return $a['price'] <=> $b['price'];
        });

        // Reset prices first
        foreach ($items as $key => $item) {
            $cart->cart_contents[$key]['data']->set_price($item['data']->get_regular_price());
            if (isset($cart->cart_contents[$key]['is_bogo_free'])) {
                unset($cart->cart_contents[$key]['is_bogo_free']);
            }
        }

        // Apply BOGO: every 2 → cheapest free
        $bundle_count = floor(count($expanded) / 2);
        for ($i = 0; $i < $bundle_count; $i++) {
            $free_item = $expanded[$i * 2];
            $cart->cart_contents[$free_item['key']]['data']->set_price(0);
            $cart->cart_contents[$free_item['key']]['is_bogo_free'] = true; // <-- flag
        }
    }
}


// =====================
// 4. Disable quantity change for BOGO items
// =====================
add_filter('woocommerce_cart_item_quantity', 'disable_qty_for_bogo_items', 10, 3);
function disable_qty_for_bogo_items($quantity_html, $cart_item_key, $cart_item) {
    if (is_bogo_item($cart_item) || !empty($cart_item['is_bogo_free'])) {
        return '<span class="bogo-qty-locked">' . esc_html($cart_item['quantity']) . '</span>';
    }
    return $quantity_html;
}

add_filter('woocommerce_update_cart_validation', 'prevent_qty_change_for_bogo', 10, 4);
function prevent_qty_change_for_bogo($passed, $cart_item_key, $values, $quantity) {
    if (is_bogo_item($values) || !empty($values['is_bogo_free'])) {
        wc_add_notice(__('This product quantity cannot be changed due to an active BOGO offer.'), 'notice');
        return false;
    }
    return $passed;
}

add_filter('woocommerce_cart_item_name', 'show_bogo_badge_cart_item', 10, 3);
function show_bogo_badge_cart_item($name, $cart_item, $cart_item_key) {

    if (!empty($cart_item['is_bogo_free'])) {
        $name .= ' <span class="bogo-badge-free" style="
            background: #ff4d4d;
            color: #fff;
            font-size: 12px;
            padding: 2px 5px;
            border-radius: 3px;
            margin-left: 5px;
            text-transform: uppercase;
        ">Free</span>';
    } elseif (is_bogo_item($cart_item)) {
        $name .= ' <span class="bogo-badge" style="
            background: #c41230;
            color: #fff;
            font-size: 12px;
            padding: 2px 5px;
            border-radius: 3px;
            margin-left: 5px;
            text-transform: uppercase;
        ">BOGO</span>';
    }

    return $name;
}



// =====================
// 6. Optional: Show BOGO badge on Shop / Archive Page
// =====================
add_action('woocommerce_before_shop_loop_item_title', 'show_bogo_badge_on_shop', 10);
function show_bogo_badge_on_shop() {
    global $product;
    $product_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();

    foreach (get_bogo_categories() as $slug) {
        if (has_term($slug, 'product_cat', $product_id)) {
            echo '<span class="bogo-badge" style="
                position: absolute;
                top: 0px;
                left: 0px;
                background: #C41230;
                color: #fff;
                font-size: 12px;
                padding: 0px 5px;
                z-index: 9;
                text-transform: uppercase;
            ">BOGO</span>';
            break;
        }
    }
}

// =====================
// Fully separate each BOGO unit in cart
// =====================
add_filter('woocommerce_add_cart_item_data', function ($cart_item_data, $product_id, $variation_id) {
    $product = wc_get_product($variation_id ?: $product_id);
    if (is_bogo_item(['data' => $product])) {
        // Add a unique key to prevent merging
        $cart_item_data['bogo_unique'] = uniqid('bogo_', true);
    }
    return $cart_item_data;
}, 10, 3);

add_filter('woocommerce_add_to_cart_quantity', function ($quantity, $product_id) {
    $product = wc_get_product($product_id);
    if (is_bogo_item(['data' => $product])) {
        // Force adding one item at a time
        return 1;
    }
    return $quantity;
}, 10, 2);

add_action('woocommerce_add_to_cart', function ($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
    $product = wc_get_product($variation_id ?: $product_id);
    if (is_bogo_item(['data' => $product]) && $quantity > 1) {
        for ($i = 1; $i < $quantity; $i++) {
            // Add remaining quantities individually
            WC()->cart->add_to_cart($product_id, 1, $variation_id, $variation, $cart_item_data);
        }
    }
}, 20, 6);


add_action('woocommerce_add_to_cart', function($cart_item_key, $product_id, $quantity){
    $product = wc_get_product($product_id);
    error_log('Added to cart: '.$product->get_name().' qty='.$quantity);
}, 10, 3);

