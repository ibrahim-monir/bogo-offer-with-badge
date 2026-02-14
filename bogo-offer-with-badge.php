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


add_filter('woocommerce_cart_item_name', 'show_bogo_explanation_in_cart', 20, 3);
function show_bogo_explanation_in_cart($name, $cart_item, $cart_item_key) {
    if (!is_bogo_item($cart_item) || !empty($cart_item['is_bogo_free'])) {
        return $name; // Skip free items or non-BOGO
    }

    global $woocommerce;
    $product = $cart_item['data'];
    $product_id = $product->get_parent_id() ?: $product->get_id();

    // Find the BOGO category for this product
    $category_slug = '';
    foreach (get_bogo_categories() as $slug) {
        if (has_term($slug, 'product_cat', $product_id)) {
            $category_slug = $slug;
            break;
        }
    }

    if (!$category_slug) return $name;

    // Count total quantity of items in this category
    $category_qty = 0;
    foreach (WC()->cart->get_cart() as $item) {
        $item_id = $item['data']->get_parent_id() ?: $item['data']->get_id();
        if (has_term($category_slug, 'product_cat', $item_id)) {
            $category_qty += $item['quantity'];
        }
    }

    // Show notice only if quantity is odd (i.e., free item not yet applied)
    // if ($category_qty % 2 !== 0) {
    //     $category_link = get_term_link($category_slug, 'product_cat');
    //     $name .= '<br><small style="color:#ff9900;">
    //         Add one more item from <a href="' . esc_url($category_link) . '" style="color:#ff9900;text-decoration:underline;">this category</a> to get one free!
    //     </small>';
    // }
    
    if ($category_qty % 2 !== 0) {
    $category = get_term_by('slug', $category_slug, 'product_cat'); // Get the category object
    if ($category && !is_wp_error($category)) {
        $category_link = get_term_link($category, 'product_cat');
        $category_name = $category->name; // Get the category name
        $name .= '<br><span style="color:#ff9900;">
           🎁 Add another <a href="' . esc_url($category_link) . '" style="background:#F46725; color: #fff; padding: 2px 5px; border-radius: 3px; font-size: 14px; text-decoration: underline;">' . esc_html($category_name) . '</a> FREE of cost!
        </span>';
    }
}

    return $name;
}
// Cart upper code working fine
add_action('woocommerce_after_add_to_cart_button', function () {
    global $product;

    if (!$product || !$product->is_type('variable')) return;

    // Show notice only for "men-panjabi" and "jeans" categories
    $allowed_categories = ['contrast-stitch', 'cotton-casual-full-sleeve-shirt', 'cotton-casual-half-sleeve-shirt', 'denim-shirt', 'flannel-shirt', 'kaftan-shirt', 'sweatshirt', 'turtle-neck', 'full-sleeves'];

    if (has_term($allowed_categories, 'product_cat', $product->get_id())) {
        echo '<div class="bogo-single-notice" style="display:block;margin-top:12px;padding:8px 12px; background: #F36429;color:#fff; margin-bottom: 10px;">
            🎁 <strong>BOGO Offer:</strong> Grab an item and get a second one FREE in your <a href="/cart" style="text-decoration: underline; color: #fff; font-weight: 600;">Cart</a>!
        </div>';
    }
});

// Bogo Works Fine (Upper Code Okay)

// =====================
// BOGO Categories (RENAMED – no conflict)
// =====================
if (!function_exists('bogo_popup_categories')) {
    function bogo_popup_categories() {
        return [
            'contrast-stitch',
            'cotton-casual-full-sleeve-shirt',
            'cotton-casual-half-sleeve-shirt',
            'denim-shirt',
            'flannel-shirt',
            'kaftan-shirt',
            'sweatshirt',
            'turtle-neck',
            'full-sleeves'
        ];
    }
}

// =====================
// Flag BOGO products for popup (category-based)
// =====================
add_filter('woocommerce_add_cart_item_data', function ($cart_item_data, $product_id) {

    // Run only during real add-to-cart
    if (!isset($_REQUEST['add-to-cart'])) {
        return $cart_item_data;
    }

    $product = wc_get_product($product_id);
    if (!$product) {
        return $cart_item_data;
    }

    // Handle variations
    $check_id = $product->is_type('variation')
        ? $product->get_parent_id()
        : $product_id;

    if (has_term(bogo_popup_categories(), 'product_cat', $check_id)) {
        $cart_item_data['bogo_popup'] = true;
        
        // ✅ ADD THIS LINE
    WC()->session->set('bogo_popup_trigger', '1');
    }

    return $cart_item_data;

}, 20, 2);

add_action('wp_footer', function () {
    if (WC()->session->get('bogo_popup_trigger') === '1') {
        ?>
        <script>
            wc_cart_fragments_params.bogo_popup_trigger = true;
        </script>
        <?php

        // ✅ IMPORTANT: clear flag immediately
        WC()->session->__unset('bogo_popup_trigger');
    }
});


// =====================
// Mark BOGO products on add-to-cart button
// =====================
add_action('woocommerce_after_shop_loop_item', 'mark_bogo_product_button', 9);
add_action('woocommerce_after_add_to_cart_button', 'mark_bogo_product_button', 9);

function mark_bogo_product_button() {
    global $product;
    if (!$product) return;

    $check_id = $product->is_type('variation')
        ? $product->get_parent_id()
        : $product->get_id();

    if (has_term(bogo_popup_categories(), 'product_cat', $check_id)) {
        echo '<script>
            jQuery(function($){
                $(".add_to_cart_button, .single_add_to_cart_button")
                    .last()
                    .attr("data-bogo-popup","1");
            });
        </script>';
    }
}


// =====================
// BOGO popup trigger fragment (ONLY for BOGO items)
// =====================


// =====================
// BOGO Add-to-Cart Popup HTML
// =====================
add_action('wp_footer', function () {
?>
<div id="bogo-cart-popup" style="display:none;">
    <div class="bogo-popup-overlay"></div>
    <div class="bogo-popup-content">
        <span class="bogo-popup-close">&times;</span>
        <h4>🎁 Congrats! Select your BOGO Item</h4>
        <div class="bogo-popup-body">
            <img id="bogo-popup-image" src="" alt="">
            <div class="bogo-popup-info">
                <p><strong id="bogo-popup-title"></strong></p>
                <p><strong>Quantity:</strong> 1</p>
                <p><strong>Price:</strong> <span id="bogo-popup-price"></span></p>
                <p><strong>Note:</strong> Free item must be from the same category</p>
            </div>
        </div>
        <div class="bogo-popup-actions">
            <button class="bogo-btn continue-shopping">Select Your Free One</button>
        </div>
    </div>
</div>
<?php
});

// =====================
// BOGO Popup JS (category-aware, no product ID hardcode)
// =====================
add_action('wp_footer', function () {
?>
<script>
jQuery(function ($) {

    $(document.body).on('added_to_cart', function (event, fragments, cart_hash, $button) {

        // Popup shows ONLY if cart item was flagged
        // if (!fragments || !fragments['div.widget_shopping_cart_content']) {
        //     return;
        // }
//         // if (!fragments || fragments.bogo_popup_trigger !== '1') {
//         //   return;
//         // } //Fixing Purposes
// if (!wc_cart_fragments_params || !wc_cart_fragments_params.bogo_popup_trigger) {
//     return;
// }

if (!$button || $button.data('bogo-popup') !== 1) {
    return;
}



        let productId = null;

        if ($button && $button.data('product_id')) {
            productId = parseInt($button.data('product_id'));
        }

        if (!productId) {
            let input = $('form.cart').find('input[name="add-to-cart"]');
            if (input.length) productId = parseInt(input.val());
        }

        if (!productId) return;

        // ===== Product info =====
        let img = '';
        let galleryImg = $('.woocommerce-product-gallery__wrapper img')
            .filter(function () {
                return $(this).attr('src') && !$(this).attr('src').includes('placeholder');
            }).first();

        if (galleryImg.length) img = galleryImg.attr('src');
        if (!img) img = $button.closest('.product').find('img').first().attr('src') || '';

        let title = $('.product_title').first().text() || '';
        let price = $('.summary .price').first().text().trim() ||
                    $button.closest('.product').find('.price').first().text().trim();

        $('#bogo-popup-image').attr('src', img);
        $('#bogo-popup-title').text(title);
        $('#bogo-popup-price').text(price);

        // ===== Dynamic category link =====
        $.post(
            '<?php echo admin_url("admin-ajax.php"); ?>',
            {
                action: 'bogo_get_first_product_category',
                product_id: productId
            },
            function (categoryUrl) {
                if (categoryUrl) {
                    $('#bogo-cart-popup .continue-shopping')
                        .attr('onclick', 'window.location="' + categoryUrl + '"');
                }
            }
        );

        $('#bogo-cart-popup').fadeIn();
    });

    $(document).on('click', '.bogo-popup-close, .bogo-popup-overlay', function () {
        $('#bogo-cart-popup').fadeOut();
    });

});
</script>
<?php
});

// =====================
// BOGO Popup CSS
// =====================
add_action('wp_head', function () {
?>
<style>
#bogo-cart-popup { position: fixed; inset: 0; z-index: 99999; } .bogo-popup-overlay { position: absolute; inset: 0; background: rgba(0,0,0,0.6); } .bogo-popup-content { position: relative; background: #fff; width: 700px; max-width: 92%; margin: 17% auto; padding: 20px; box-shadow: 0 8px 20px rgba(0,0,0,0.2); transition: transform 0.3s ease-in-out; } .bogo-popup-content h4{ margin-bottom: 12px; border-bottom: 1px solid #e5e5e5; padding-bottom: 10px; } .bogo-popup-close { position: absolute; right: 12px; top: 8px; font-size: 22px; cursor: pointer; } .bogo-popup-body { display: flex; gap: 15px; align-items: center; } .bogo-popup-body img { width: 120px; height: auto; display: block; border-radius: 4px; } .bogo-popup-actions { margin-top: 15px; display: flex; justify-content: center; gap: 10px; } .bogo-btn { padding: 10px 16px; background: #000 !important; color: #fff; border: none; cursor: pointer; text-decoration: none; border-radius: 4px; transition: background 0.3s; } .bogo-btn:hover { background: #333 !important; }
</style>
<?php
});

// =====================
// AJAX: get deepest category link (RENAMED handler)
// =====================
add_action('wp_ajax_bogo_get_first_product_category', 'bogo_get_first_product_category');
add_action('wp_ajax_nopriv_bogo_get_first_product_category', 'bogo_get_first_product_category');

function bogo_get_first_product_category() {

    $product_id = intval($_POST['product_id']);
    if (!$product_id) wp_send_json('');

    $terms = get_the_terms($product_id, 'product_cat');
    if (!$terms || is_wp_error($terms)) wp_send_json('');

    $child_terms = array_filter($terms, function ($t) use ($terms) {
        foreach ($terms as $other) {
            if ($other->parent == $t->term_id) return false;
        }
        return true;
    });

    $category = !empty($child_terms) ? array_shift($child_terms) : $terms[0];

    wp_send_json(get_term_link($category));
}

