<?php
/**
 * Plugin Name: СДЭК Доставка — ПВЗ
 * Description: Доставка СДЭК до пункта выдачи (ПВЗ). Склад → СДЭК → ПВЗ клиента. Расчёт стоимости, выбор ПВЗ на карте, создание заказа.
 * Version: 1.0.0
 * Author: al-nemirov
 * Requires PHP: 8.0
 * Requires at least: 6.0
 * WC requires at least: 8.0
 * Text Domain: cdek-shipping
 */

defined( 'ABSPATH' ) || exit;

define( 'CDEK_SHIP_VERSION', '1.0.0' );
define( 'CDEK_SHIP_DIR', plugin_dir_path( __FILE__ ) );
define( 'CDEK_SHIP_URL', plugin_dir_url( __FILE__ ) );

/* ═══════════════════════════════════════════════════════════
 *  1. AUTOLOAD
 * ═══════════════════════════════════════════════════════════ */

require_once CDEK_SHIP_DIR . 'includes/class-cdek-api.php';
require_once CDEK_SHIP_DIR . 'includes/class-cdek-shipping.php';


/* ═══════════════════════════════════════════════════════════
 *  2. REGISTER SHIPPING METHOD
 * ═══════════════════════════════════════════════════════════ */

add_filter( 'woocommerce_shipping_methods', function ( $methods ) {
    $methods['cdek_pvz'] = 'CDEK_Shipping_Method';
    return $methods;
} );


/* ═══════════════════════════════════════════════════════════
 *  3. ADMIN SETTINGS PAGE
 * ═══════════════════════════════════════════════════════════ */

add_action( 'admin_menu', function () {
    add_submenu_page(
        'woocommerce',
        'СДЭК Настройки',
        'СДЭК',
        'manage_woocommerce',
        'cdek-shipping',
        'cdek_ship_settings_page'
    );
} );

function cdek_ship_settings_page(): void {
    if ( isset( $_POST['cdek_ship_save'] ) && check_admin_referer( 'cdek_ship_nonce' ) ) {
        $settings = [
            'account'   => sanitize_text_field( $_POST['account'] ?? '' ),
            'secret'    => sanitize_text_field( $_POST['secret'] ?? '' ),
            'test_mode' => isset( $_POST['test_mode'] ) ? 'yes' : 'no',
        ];
        update_option( 'cdek_ship_settings', $settings );
        echo '<div class="notice notice-success"><p>Настройки сохранены.</p></div>';
    }

    $s = get_option( 'cdek_ship_settings', [
        'account'   => '',
        'secret'    => '',
        'test_mode' => 'yes',
    ] );
    ?>
    <div class="wrap">
        <h1>СДЭК — Настройки API</h1>
        <form method="post">
            <?php wp_nonce_field( 'cdek_ship_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th>Account (Client ID)</th>
                    <td>
                        <input type="text" name="account" value="<?= esc_attr( $s['account'] ?? '' ) ?>" class="regular-text">
                        <p class="description">Оставьте пустым для тестового режима (sandbox).</p>
                    </td>
                </tr>
                <tr>
                    <th>Secure (Client Secret)</th>
                    <td>
                        <input type="password" name="secret" value="<?= esc_attr( $s['secret'] ?? '' ) ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th>Тестовый режим</th>
                    <td>
                        <label>
                            <input type="checkbox" name="test_mode" <?php checked( ( $s['test_mode'] ?? 'yes' ), 'yes' ); ?>>
                            Использовать sandbox API (api.edu.cdek.ru)
                        </label>
                    </td>
                </tr>
            </table>

            <h2>Проверка подключения</h2>
            <p>
                <button type="button" id="cdek-test-btn" class="button">Тест API</button>
                <span id="cdek-test-result" style="margin-left:10px;"></span>
            </p>

            <p class="submit">
                <input type="submit" name="cdek_ship_save" class="button-primary" value="Сохранить">
            </p>
        </form>
    </div>
    <script>
    document.getElementById('cdek-test-btn').addEventListener('click', function() {
        var r = document.getElementById('cdek-test-result');
        r.textContent = 'Проверка...';
        fetch(ajaxurl + '?action=cdek_ship_test_api')
            .then(function(res){ return res.json(); })
            .then(function(data){
                if(data.success) {
                    r.innerHTML = '<span style="color:green">✓ ' + data.data + '</span>';
                } else {
                    r.innerHTML = '<span style="color:red">✗ ' + data.data + '</span>';
                }
            })
            .catch(function(e){ r.innerHTML = '<span style="color:red">✗ ' + e.message + '</span>'; });
    });
    </script>
    <?php
}

/* Test API connection */
add_action( 'wp_ajax_cdek_ship_test_api', function () {
    try {
        $s = get_option( 'cdek_ship_settings', [] );
        $api = new CDEK_Shipping_API(
            $s['account'] ?? '',
            $s['secret'] ?? '',
            ( $s['test_mode'] ?? 'yes' ) === 'yes'
        );
        $token = $api->get_token();

        // Quick test — fetch Moscow (44)
        $cities = $api->get_cities( [ 'code' => 44 ] );
        $city_name = $cities[0]['city'] ?? 'Unknown';

        wp_send_json_success( "Подключено! Токен получен. Тест: город #44 = {$city_name}" );
    } catch ( \Throwable $e ) {
        wp_send_json_error( $e->getMessage() );
    }
} );


/* ═══════════════════════════════════════════════════════════
 *  4. AJAX ENDPOINTS (frontend)
 * ═══════════════════════════════════════════════════════════ */

/**
 * AJAX: Get CDEK PVZ list for a city.
 * POST/GET wp-admin/admin-ajax.php?action=cdek_pvz&city=Москва
 */
add_action( 'wp_ajax_cdek_pvz', 'cdek_ship_ajax_pvz' );
add_action( 'wp_ajax_nopriv_cdek_pvz', 'cdek_ship_ajax_pvz' );

function cdek_ship_ajax_pvz(): void {
    $city      = sanitize_text_field( $_REQUEST['city'] ?? '' );
    $postcode  = sanitize_text_field( $_REQUEST['postcode'] ?? '' );

    if ( empty( $city ) && empty( $postcode ) ) {
        wp_send_json_error( 'Укажите город или индекс' );
    }

    try {
        $api = cdek_ship_get_api();

        // Resolve city code
        $city_code = null;
        if ( $postcode ) {
            $found = $api->find_city_by_postal( $postcode );
            $city_code = $found['code'] ?? null;
        }
        if ( ! $city_code && $city ) {
            $city_code = $api->find_city_code( $city );
        }

        if ( ! $city_code ) {
            wp_send_json_error( 'Город не найден в СДЭК' );
        }

        $points = $api->get_delivery_points_cached( $city_code, 'PVZ' );

        // Simplify for frontend
        $result = [];
        foreach ( $points as $p ) {
            $result[] = [
                'code'        => $p['code'] ?? '',
                'name'        => $p['name'] ?? '',
                'address'     => $p['location']['address_full'] ?? $p['location']['address'] ?? '',
                'city'        => $p['location']['city'] ?? '',
                'lat'         => (float) ( $p['location']['latitude'] ?? 0 ),
                'lng'         => (float) ( $p['location']['longitude'] ?? 0 ),
                'work_time'   => $p['work_time'] ?? '',
                'phone'       => $p['phones'][0]['number'] ?? '',
                'type'        => $p['type'] ?? 'PVZ',
                'have_cash'   => (bool) ( $p['have_cash'] ?? false ),
                'have_card'   => (bool) ( $p['have_cashless'] ?? false ),
                'is_dressing' => (bool) ( $p['is_dressing_room'] ?? false ),
                'images'      => array_column( $p['images'] ?? [], 'url' ),
            ];
        }

        wp_send_json_success( [
            'city_code' => $city_code,
            'count'     => count( $result ),
            'points'    => $result,
        ] );

    } catch ( \Throwable $e ) {
        wp_send_json_error( $e->getMessage() );
    }
}

/**
 * AJAX: Calculate CDEK delivery cost.
 * POST wp-admin/admin-ajax.php?action=cdek_ship_calc&city=Москва&postcode=630001
 */
add_action( 'wp_ajax_cdek_ship_calc', 'cdek_ship_ajax_calc' );
add_action( 'wp_ajax_nopriv_cdek_ship_calc', 'cdek_ship_ajax_calc' );

function cdek_ship_ajax_calc(): void {
    $city     = sanitize_text_field( $_REQUEST['city'] ?? '' );
    $postcode = sanitize_text_field( $_REQUEST['postcode'] ?? '' );

    try {
        $api = cdek_ship_get_api();

        // Get shipping method settings
        $zones = WC_Shipping_Zones::get_zones();
        $settings = [];
        foreach ( $zones as $zone ) {
            foreach ( $zone['shipping_methods'] as $method ) {
                if ( $method->id === 'cdek_pvz' ) {
                    $settings = $method->instance_settings;
                    break 2;
                }
            }
        }

        $from = [ 'postal_code' => $settings['sender_postal_code'] ?? '107023' ];
        if ( ! empty( $settings['sender_city_code'] ) ) {
            $from['code'] = (int) $settings['sender_city_code'];
        }

        $to = [];
        if ( $postcode ) $to['postal_code'] = $postcode;
        if ( $city )     $to['city'] = $city;

        // Default package for quick calc
        $packages = [ [
            'weight' => 500,
            'length' => 25,
            'width'  => 20,
            'height' => 5,
        ] ];

        $tariff_codes = $settings['tariffs'] ?? [ '136', '234' ];
        $best = null;

        foreach ( $tariff_codes as $code ) {
            try {
                $result = $api->calculate_tariff( (int) $code, $from, $to, $packages );
                if ( ! empty( $result['delivery_sum'] ) ) {
                    $sum = (float) $result['delivery_sum'];
                    if ( ! $best || $sum < $best['delivery_sum'] ) {
                        $best = [
                            'tariff_code' => (int) $code,
                            'delivery_sum' => $sum,
                            'period_min'   => (int) ( $result['period_min'] ?? 0 ),
                            'period_max'   => (int) ( $result['period_max'] ?? 0 ),
                        ];
                    }
                }
            } catch ( \RuntimeException $e ) {
                continue;
            }
        }

        if ( $best ) {
            wp_send_json_success( $best );
        } else {
            wp_send_json_error( 'Доставка в этот город недоступна' );
        }

    } catch ( \Throwable $e ) {
        wp_send_json_error( $e->getMessage() );
    }
}

/**
 * AJAX: Search CDEK cities (for autocomplete).
 */
add_action( 'wp_ajax_cdek_ship_cities', 'cdek_ship_ajax_cities' );
add_action( 'wp_ajax_nopriv_cdek_ship_cities', 'cdek_ship_ajax_cities' );

function cdek_ship_ajax_cities(): void {
    $q = sanitize_text_field( $_REQUEST['q'] ?? '' );
    if ( mb_strlen( $q ) < 2 ) {
        wp_send_json_success( [] );
    }

    try {
        $api = cdek_ship_get_api();
        $cities = $api->get_cities( [ 'city' => $q, 'size' => 10 ] );

        $result = [];
        foreach ( $cities as $c ) {
            $result[] = [
                'code'        => (int) $c['code'],
                'city'        => $c['city'] ?? '',
                'region'      => $c['region'] ?? '',
                'postal_code' => $c['postal_codes'][0] ?? '',
            ];
        }

        wp_send_json_success( $result );
    } catch ( \Throwable $e ) {
        wp_send_json_error( $e->getMessage() );
    }
}

/** Helper: get configured API instance */
function cdek_ship_get_api(): CDEK_Shipping_API {
    static $api = null;
    if ( ! $api ) {
        $s = get_option( 'cdek_ship_settings', [] );
        $api = new CDEK_Shipping_API(
            $s['account'] ?? '',
            $s['secret'] ?? '',
            ( $s['test_mode'] ?? 'yes' ) === 'yes'
        );
    }
    return $api;
}


/* ═══════════════════════════════════════════════════════════
 *  5. CHECKOUT FRONTEND (scripts + PVZ modal)
 * ═══════════════════════════════════════════════════════════ */

add_action( 'wp_enqueue_scripts', function () {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;

    wp_enqueue_style(
        'cdek-ship-checkout',
        CDEK_SHIP_URL . 'assets/cdek-checkout.css',
        [],
        CDEK_SHIP_VERSION
    );

    wp_enqueue_script(
        'cdek-ship-checkout',
        CDEK_SHIP_URL . 'assets/cdek-checkout.js',
        [ 'jquery' ],
        CDEK_SHIP_VERSION,
        true
    );

    wp_localize_script( 'cdek-ship-checkout', 'cdekShip', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'cdek_ship_nonce' ),
    ] );
} );


/* ═══════════════════════════════════════════════════════════
 *  6. SAVE SELECTED PVZ TO ORDER META
 * ═══════════════════════════════════════════════════════════ */

add_action( 'woocommerce_checkout_update_order_meta', function ( $order_id ) {
    if ( ! empty( $_POST['cdek_pvz_code'] ) ) {
        $order = wc_get_order( $order_id );
        $order->update_meta_data( '_cdek_pvz_code', sanitize_text_field( $_POST['cdek_pvz_code'] ) );
        $order->update_meta_data( '_cdek_pvz_address', sanitize_text_field( $_POST['cdek_pvz_address'] ?? '' ) );
        $order->save();
    }
} );

/* Show PVZ info in admin order */
add_action( 'woocommerce_admin_order_data_after_shipping_address', function ( $order ) {
    $pvz_code = $order->get_meta( '_cdek_pvz_code' );
    if ( $pvz_code ) {
        $pvz_addr = $order->get_meta( '_cdek_pvz_address' );
        echo '<p><strong>СДЭК ПВЗ:</strong> ' . esc_html( $pvz_code );
        if ( $pvz_addr ) echo '<br>' . esc_html( $pvz_addr );
        echo '</p>';
    }
} );


/* ═══════════════════════════════════════════════════════════
 *  7. HIDDEN CHECKOUT FIELD FOR PVZ CODE
 * ═══════════════════════════════════════════════════════════ */

add_action( 'woocommerce_after_order_notes', function () {
    echo '<input type="hidden" name="cdek_pvz_code" id="cdek_pvz_code" value="">';
    echo '<input type="hidden" name="cdek_pvz_address" id="cdek_pvz_address" value="">';
} );
