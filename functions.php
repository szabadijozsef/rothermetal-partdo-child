<?php
/**
 * functions.php
 * @package WordPress
 * @subpackage Partdo Child
 * @since Partdo Child 1.0
 */
add_action('wp_footer', function() {
    if ( current_user_can('manage_options') && is_product() ) {
        global $product;

        $regular   = $product->get_regular_price();
        $sale      = $product->get_sale_price();
        $user      = wp_get_current_user();
        $is_wholesale = in_array('wholesale_customer', (array)$user->roles, true);
        $wholesale = $is_wholesale ? get_post_meta($product->get_id(), 'wholesale_customer_wholesale_price', true) : '';

        echo '<pre style="background:#222;color:#0f0;padding:10px;">';
        echo "DEBUG árak\n";
        echo "Termék ID: " . $product->get_id() . "\n";
        echo "Katalógus: " . var_export($regular, true) . "\n";
        echo "Akciós: " . var_export($sale, true) . "\n";
        echo "Wholesale: " . var_export($wholesale, true) . "\n";
        echo "</pre>";
    }
});

/* ----------------------
2) Parent style betöltése
---------------------- */
add_action('wp_enqueue_scripts', 'partdo_enqueue_styles', 99 );
function partdo_enqueue_styles() {
    wp_enqueue_style( 'parent-style', get_template_directory_uri() . '/style.css' );
    wp_style_add_data( 'parent-style', 'rtl', 'replace' );
}

/* ----------------------
3) WooCommerce tabok teljes törlése
---------------------- */
add_filter('woocommerce_product_tabs', function($tabs) {
    return [];
}, 9999);

/* ----------------------
4) FiboFilters tiltása keresésnél
---------------------- */
add_action('wp_enqueue_scripts', function() {
    if (is_search()) {
        wp_dequeue_script('fibofilters');
        wp_dequeue_style('fibofilters');
    }
}, 100);

/* ----------------------
5) "Recently Viewed Products" fordítás
---------------------- */
add_filter('gettext', function($translated, $original, $domain) {
    if ($original === 'Recently Viewed Products') {
        return 'Legutóbb megtekintett termékek';
    }
    return $translated;
}, 10, 3);

/* ----------------------
6) Flat rate extra mező az adminban
---------------------- */
add_filter('woocommerce_shipping_instance_form_fields_flat_rate', function($settings) {
    $settings['shipping_extra_field'] = [
        'title'       => __('Note', 'woocommerce'),
        'type'        => 'text',
        'placeholder' => 'shipping',
        'description' => '',
        'default'     => '',
    ];
    return $settings;
}, 10);

/* ----------------------
7) Extra shipping info a checkout összegzésnél
---------------------- */
add_action('woocommerce_review_order_after_shipping', function() {
    $chosen_methods = WC()->session->get('chosen_shipping_methods');
    if (empty($chosen_methods)) return;

    $chosen_shipping_method = $chosen_methods[0];
    $order_id = WC()->session->get('order_id');
    $shipping_extra_field_content = $order_id ? get_post_meta($order_id, '_' . $chosen_shipping_method . '_shipping_extra_field', true) : '';

    if (empty($shipping_extra_field_content) || $shipping_extra_field_content === 'N/A') {
        $shipping_extra_field_content = 'Nettó 100,00 € alatti rendelés esetén nettó 9 € szállítási díjat számítunk fel. Amennyiben a termék jellege vagy súlya miatt speciális szállítási módot vagy csomagolást igényel, a megrendelés visszaigazolásban eltérő szállítási költséget tüntethetünk fel.';
    }

    echo '<tr class="shipping-extra-field">';
    echo '<th>FONTOS:</th>';
    echo '<td>' . esc_html($shipping_extra_field_content) . '</td>';
    echo '</tr>';
});

/* ----------------------
8) Wholesale ár – normál akciós ár eltávolítása (eredeti plugin markup takarítás)
---------------------- */
add_filter('woocommerce_get_price_html', function($price_html, $product) {
    if (strpos($price_html, 'wholesale_price_container') !== false) {
        $price_html = preg_replace('/<ins\b[^>]*aria-hidden=["\']true["\'][^>]*>.*?<\/ins>/si', '', $price_html);
        $price_html = preg_replace('/<span\b[^>]*class=["\']screen-reader-text["\'][^>]*>.*?<\/span>/si', '', $price_html);
    }
    return $price_html;
}, PHP_INT_MAX, 2);

/* ----------------------
9) Debug notice _global_unique_id mezőre
---------------------- */
add_action('save_post_product', function($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    $val = get_post_meta($post_id, '_global_unique_id', true);
    add_action('admin_notices', function() use ($val) {
        echo '<div class="notice notice-info is-dismissible"><p><strong>DEBUG:</strong> _global_unique_id = ' . esc_html($val) . '</p></div>';
    });
}, 9999);

/* ----------------------
10) Biztonságos remove filter / action
---------------------- */
if (has_filter('woocommerce_is_purchasable', 'pqfw_is_purchasable')) {
    remove_filter('woocommerce_is_purchasable', 'pqfw_is_purchasable', 10);
}
if (has_filter('woocommerce_get_price_html', 'pqfw_change_price_display')) {
    remove_filter('woocommerce_get_price_html', 'pqfw_change_price_display', 10);
}
if (has_action('wp', 'ekit_track_post_views')) {
    remove_action('wp', 'ekit_track_post_views');
}

/* ----------------------
11) W3TC fragment cache kulcs bővítése WooCommerce cookie-kkal
---------------------- */
add_filter('w3tc_fragmentcache_groups', function($groups) {
    $groups['mini_cart_fragment'] = [
        'cookies' => [
            'woocommerce_cart_hash',
            'wp_woocommerce_session_'
        ]
    ];
    return $groups;
});

/* ----------------------
12) Cache-elt product meta
---------------------- */
function partdo_get_cached_meta($product, $key, $ttl = 21600) {
    $post_id = $product->get_id();
    $cache_key = 'product_meta_' . $key . '_' . $post_id;

    $value = wp_cache_get($cache_key, 'product_meta');

    if ($value === false) {
        $value = $product->get_meta($key);
        wp_cache_set($cache_key, $value, 'product_meta', $ttl);
    }

    return $value;
}

/* ----------------------
13) Cache-elt breadcrumb
---------------------- */
add_filter('woocommerce_breadcrumb_main_content', function($crumbs, $args) {
    global $post;
    $cache_key = 'breadcrumb_' . ($post ? $post->ID : 'shop');

    $cached = wp_cache_get($cache_key, 'breadcrumbs');
    if ($cached !== false) {
        return $cached;
    }

    wp_cache_set($cache_key, $crumbs, 'breadcrumbs', 6 * HOUR_IN_SECONDS);
    return $crumbs;
}, 10, 2);

/* ----------------------
14) ÁRLOGIKA: Katalógus / Akciós / Wholesale ár megjelenítés + DEBUG
---------------------- */
/**
 * VÉGLEGES ár-logika: Katalógus / Akciós / Wholesale
 * - Katalógus ár mindig látszik.
 * - Akkor legyen áthúzva a katalógus ár, ha VAN akciós VAGY VAN wholesale.
 * - Normál user: ha van akció, lássa az akciós árat.
 * - Wholesale user:
 *    - ha NINCS wholesale ár, de VAN akció → az akciós árat lássa (katalógus áthúzva).
 *    - ha VAN wholesale ár és az OLCSÓBB, mint az akció → wholesale ár látszik.
 *    - ha VAN wholesale ár, de DRÁGÁBB vagy = az akcióhoz → akciós ár látszik.
 */
add_filter('woocommerce_get_price_html', function ($price_html, $product) {

    // Nyers számok (string) és numerikus összehasonlításhoz float
    $regular_raw = $product->get_regular_price();
    $sale_raw    = $product->get_sale_price();

    $has_regular = ($regular_raw !== '' && $regular_raw !== null);
    $has_sale    = ($sale_raw    !== '' && $sale_raw    !== null);

    $regular_f = $has_regular ? (float) wc_format_decimal($regular_raw) : null;
    $sale_f    = $has_sale    ? (float) wc_format_decimal($sale_raw)    : null;

    // Wholesale user és ár
    $user          = wp_get_current_user();
    $is_wholesale  = in_array('wholesale_customer', (array) $user->roles, true);
    $wholesale_raw = $is_wholesale ? get_post_meta($product->get_id(), 'wholesale_customer_wholesale_price', true) : '';
    $has_wholesale = ($wholesale_raw !== '' && $wholesale_raw !== null);
    $wholesale_f   = $has_wholesale ? (float) wc_format_decimal($wholesale_raw) : null;

    // Döntés: mit mutassunk a KATALÓGUS mellé
    // – akciós ár megjelenítésének feltétele
    $show_sale =
        (!$is_wholesale && $has_sale) ||                                         // normál user + akció
        ($is_wholesale && $has_sale && (!$has_wholesale || $wholesale_f >= $sale_f)); // wholesale user: nincs wholesale VAGY wholesale >= akció

    // – wholesale ár megjelenítésének feltétele
    $show_wholesale =
        ($is_wholesale && $has_wholesale && (!$has_sale || $wholesale_f < $sale_f)); // wholesale olcsóbb az akciónál, vagy nincs akció

    // – katalógus áthúzás
    $catalog_strike = ($show_sale || $show_wholesale);

    // HTML összeállítás (FIGYELEM: NINCS <div>, csak <span>, hogy ne törjük meg a téma markupját)
    $out  = '';

    // Katalógus ár – mindig látszik, szóközzel a felirat és az ár között
    $out .= '<span class="price-row catalog-price">';
    $out .= '<span class="custom-label">Katalógus ár:</span> ';
    $out .= '<span class="woocommerce-Price-amount amount"'
         .  ($catalog_strike ? ' style="text-decoration: line-through;"' : '')
         .  '>' . wc_price($regular_f) . '</span>';
    $out .= '</span>';

    // Második sor: vagy Akciós ár, vagy Wholesale ár (egyszerre csak az egyik)
    if ($show_sale) {
        $out .= '<span class="price-row sale-price">';
        $out .= '<span class="custom-label">Akciós ár:</span> ';
        $out .= '<span class="woocommerce-Price-amount amount">' . wc_price($sale_f) . '</span>';
        $out .= '</span>';
    } elseif ($show_wholesale) {
        $out .= '<span class="price-row wholesale-price">';
        $out .= '<span class="custom-label">Az Ön ára:</span> ';
        $out .= '<span class="woocommerce-Price-amount amount">' . wc_price($wholesale_f) . '</span>';
        $out .= '</span>';
    }

    return $out;
}, 9999, 2);



// WP-CLI parancs: wp rothermetal sync-onsale
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('rothermetal sync-onsale', function() {
        global $wpdb;

        WP_CLI::log("🔄 Akciós termékek státuszának frissítése folyamatban...");

        // SQL frissítés: onsale mező beállítása a _sale_price alapján
        $updated = $wpdb->query("
            UPDATE {$wpdb->wc_product_meta_lookup} l
            LEFT JOIN {$wpdb->postmeta} sp
                ON sp.post_id = l.product_id
                AND sp.meta_key = '_sale_price'
            SET l.onsale = IF(CAST(sp.meta_value AS DECIMAL(10,2)) > 0, 1, 0)
        ");

        // Ellenőrzés: mennyi termék van most akcióban
        $count = $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->wc_product_meta_lookup} WHERE onsale = 1
        ");

        WP_CLI::success("✅ Akciós státusz frissítve! Most {$count} termék akciós.");
    });
}
// Akciós termékek szinkronizáló gomb az adminban
add_action('admin_menu', function() {
    add_submenu_page(
        'woocommerce', // WooCommerce menü alá kerül
        'Akciós termékek szinkronizálása',
        'Akciós termékek szinkronizálása',
        'manage_woocommerce',
        'rothermetal-sync-onsale',
        'rothermetal_sync_onsale_page'
    );
});

function rothermetal_sync_onsale_page() {
    global $wpdb;

    echo '<div class="wrap">';
    echo '<h1>Akciós termékek szinkronizálása</h1>';

    if (isset($_POST['rothermetal_sync'])) {
        @set_time_limit(0);

        // onsale mező frissítése az _sale_price alapján
        $updated = $wpdb->query("
            UPDATE {$wpdb->wc_product_meta_lookup} l
            LEFT JOIN {$wpdb->postmeta} sp
                ON sp.post_id = l.product_id
                AND sp.meta_key = '_sale_price'
            SET l.onsale = IF(CAST(sp.meta_value AS DECIMAL(10,2)) > 0, 1, 0)
        ");

        // Frissítés után lekérdezzük, hány akciós termék van
        $count = $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->wc_product_meta_lookup} WHERE onsale = 1
        ");

        echo '<div class="notice notice-success"><p>✅ Szinkronizálás kész! Jelenleg <strong>' . intval($count) . '</strong> akciós termék van.</p></div>';
    }

    echo '<form method="post">';
    echo '<p><input type="submit" name="rothermetal_sync" class="button button-primary" value="Szinkronizálás most"></p>';
    echo '</form>';
    echo '</div>';
}
// Csak akciós termékek megjelenítése az "Akciós termékek" oldalon (FiboFilters kompatibilis)
add_action( 'pre_get_posts', 'rothermetal_filter_sale_products_fibofilters', 20 );
function rothermetal_filter_sale_products_fibofilters( $query ) {

    // Csak fő lekérdezésre és frontenden fusson
    if ( is_admin() || ! $query->is_main_query() ) {
        return;
    }

    // Csak az "Akciós termékek" oldalon működjön
    if ( ! is_page( 'akcios-termekek' ) ) {
        return;
    }

    // Meta query bővítése, nem felülírása, hogy FiboFilters működjön
    $meta_query = (array) $query->get( 'meta_query' );
    $meta_query[] = array(
        'key'     => '_sale_price',
        'value'   => 0,
        'compare' => '>',
        'type'    => 'NUMERIC',
    );

    // Ha nincs kapcsolat, legyen AND
    if ( ! isset( $meta_query['relation'] ) ) {
        $meta_query['relation'] = 'AND';
    }

    $query->set( 'meta_query', $meta_query );
}
// ------- Akciós oldal beállítások -------
if ( ! defined('AKCIOS_PAGE_ID') ) {
    define('AKCIOS_PAGE_ID', 0); // pl. 58508 (ha tudod az oldal ID-ját)
}

// Eldöntjük, hogy épp az Akciós oldalon vagyunk-e (slug, ID, FiboFilters AJAX eset)
function rm_is_akcios_ctx() {
    $is_slug = is_page('akcios-termekek');
    $is_id   = ( AKCIOS_PAGE_ID > 0 ) ? is_page(AKCIOS_PAGE_ID) : false;

    // FiboFilters AJAX kérésekben jöhet az oldal ID
    $ff_id   = isset($_REQUEST['ff_page_id']) ? (int) $_REQUEST['ff_page_id'] : 0;
    $is_ff   = $ff_id ? ( 'akcios-termekek' === get_post_field('post_name', $ff_id) || ( AKCIOS_PAGE_ID && $ff_id === AKCIOS_PAGE_ID ) ) : false;

    return ( $is_slug || $is_id || $is_ff );
}

// a) Fő/archív lekérdezés: hozzáfűzzük az akciós feltételt (nem felülírjuk a meta_query-t)
add_action( 'pre_get_posts', function( $query ){
    if ( is_admin() ) return;
    if ( ! rm_is_akcios_ctx() ) return;

    $meta = (array) $query->get( 'meta_query' );
    $meta[] = array(
        'key'     => '_sale_price',
        'value'   => 0,
        'compare' => '>',
        'type'    => 'NUMERIC',
    );
    if ( empty($meta['relation']) ) $meta['relation'] = 'AND';
    $query->set( 'meta_query', $meta );
}, 20 );

// b) A [products] shortcode saját lekérdezését is kiegészítjük ugyanígy
add_filter( 'woocommerce_shortcode_products_query', function( $args, $atts, $type ){
    if ( ! rm_is_akcios_ctx() ) return $args;
    if ( empty($args['meta_query']) ) $args['meta_query'] = array();
    $args['meta_query'][] = array(
        'key'     => '_sale_price',
        'value'   => 0,
        'compare' => '>',
        'type'    => 'NUMERIC',
    );
    return $args;
}, 10, 3 );
// Child stílusok betöltése a parent után
add_action('wp_enqueue_scripts', function () {
    // parent már töltve 'parent-style' néven (ld. fent)
    $ver_child = file_exists(get_stylesheet_directory() . '/style.css')
        ? filemtime(get_stylesheet_directory() . '/style.css')
        : null;

    // ha van külön base.css a childban, azt is be lehet húzni (opcionális)
    if (file_exists(get_stylesheet_directory() . '/base.css')) {
        wp_enqueue_style(
            'child-base',
            get_stylesheet_directory_uri() . '/base.css',
            ['parent-style'],
            filemtime(get_stylesheet_directory() . '/base.css')
        );
    }

    // child style.css – mindig a legvégén, hogy mindent felülírjon
    wp_enqueue_style(
        'child-style',
        get_stylesheet_uri(),
        ['parent-style', 'child-base'],
        $ver_child
    );
}, 100);


// Árboxok stílus egyesítése
add_action('wp_footer', function () {
    if ( ! is_product() ) return;
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
      const sale = document.querySelector('body.single-product .product-detail p.price .price-row.sale-price');
      const wh   = document.querySelector('body.single-product .product-detail p.price .price-row.wholesale-price');
      if (!wh) return;

      // ha van "Katalógus ár", az marad felül; a wholesale menjen alá
      const catalog = document.querySelector('body.single-product .product-detail p.price .price-row.catalog-price');
      if (catalog && wh && catalog.nextSibling !== wh) {
        catalog.parentNode.insertBefore(wh, catalog.nextSibling);
      }

      // ha van akciós doboz, másoljuk rá a számított stílusokat (méret, padding, tipó, elrendezés)
      if (sale) {
        const cs = window.getComputedStyle(sale);
        wh.style.display      = cs.display || 'inline-block';
        wh.style.padding      = cs.padding;
        wh.style.margin       = cs.margin;
        wh.style.fontSize     = cs.fontSize;
        wh.style.lineHeight   = cs.lineHeight;
        wh.style.fontWeight   = cs.fontWeight;
        wh.style.letterSpacing= cs.letterSpacing;
        wh.style.textTransform= cs.textTransform;
      } else {
        // ha nincs akciós doboz, adjunk alapokat
        wh.style.display    = 'inline-block';
        wh.style.padding    = '6px 12px';
        wh.style.lineHeight = '1.4';
        wh.style.fontWeight = '700';
      }

      // és itt jönnek a te kéréseid:
      wh.style.background   = '#2e7d32'; // zöld
      wh.style.color        = '#fff';
      wh.style.borderRadius = '0';       // nincs lekerekítés

      // belső elemek színe és kis hézag a címke és összeg között
      wh.querySelectorAll('.custom-label, .woocommerce-Price-amount').forEach(el => {
        el.style.color = '#fff';
        el.style.whiteSpace = 'nowrap';
      });
      const label = wh.querySelector('.custom-label');
      if (label) label.style.marginRight = '0.5em';
    });
    </script>
    <?php
}, 999);

// Biztosan töröljük a duplikált add-to-cart gombot
function rm_force_remove_loop_add_to_cart() {
    // minden lehetséges hookról levesszük
    remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10 );
    remove_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_template_loop_add_to_cart', 10 );
    remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_add_to_cart', 10 );
}
add_action( 'init', 'rm_force_remove_loop_add_to_cart', 20 );
// Készlet állapot teljes eltávolítása a HTML-ből
add_filter( 'woocommerce_get_stock_html', '__return_empty_string' );


