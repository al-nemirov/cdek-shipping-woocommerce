<?php
/**
 * Plugin Name: СДЭК Доставка — ПВЗ
 * Plugin URI: https://github.com/al-nemirov/cdek-shipping-woocommerce
 * Description: Доставка СДЭК до пункта выдачи. Расчёт стоимости, выбор ПВЗ на карте, создание заказов, трекинг, этикетки.
 * Version: 1.3.8
 * Author: Al Nemirov
 * Author URI: https://github.com/al-nemirov
 * Requires PHP: 8.0
 * Requires at least: 6.0
 * WC requires at least: 8.0
 * Text Domain: cdek-shipping
 * License: MIT
 */

defined( 'ABSPATH' ) || exit;

define( 'CDEK_SHIP_VERSION', '1.3.8' );
define( 'CDEK_SHIP_DIR', plugin_dir_path( __FILE__ ) );
define( 'CDEK_SHIP_URL', plugin_dir_url( __FILE__ ) );
define( 'CDEK_SHIP_FILE', __FILE__ );

/* ═══════════════════════════════════════════════════════════
 *  1. AUTOLOAD
 * ═══════════════════════════════════════════════════════════ */

require_once CDEK_SHIP_DIR . 'includes/class-cdek-api.php';

// Admin only
if ( is_admin() ) {
    require_once CDEK_SHIP_DIR . 'includes/class-cdek-admin.php';
    require_once CDEK_SHIP_DIR . 'includes/class-cdek-intake-admin.php';
    require_once CDEK_SHIP_DIR . 'includes/class-cdek-orders-page.php';
    require_once CDEK_SHIP_DIR . 'includes/class-cdek-hub.php';
    // Единая точка входа — Hub объединяет всё
    CDEK_Hub::init();
}


/* ═══════════════════════════════════════════════════════════
 *  2. HPOS COMPATIBILITY
 * ═══════════════════════════════════════════════════════════ */

add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            CDEK_SHIP_FILE,
            true
        );
    }
} );


/* ═══════════════════════════════════════════════════════════
 *  3. REGISTER SHIPPING METHOD (after WC_Shipping_Method is available)
 * ═══════════════════════════════════════════════════════════ */

add_action( 'woocommerce_shipping_init', function () {
    require_once CDEK_SHIP_DIR . 'includes/class-cdek-shipping.php';
} );

add_filter( 'woocommerce_shipping_methods', function ( $methods ) {
    $methods['cdek_pvz'] = 'CDEK_Shipping_Method';
    return $methods;
} );

// НДС на доставку СДЭК: покупатель видит стоимость доставки уже с НДС.
// Ставка % — из настроек (delivery_vat_percent, по умолчанию 22). Округление ВВЕРХ до рубля.
// 0 — без НДС. На другие методы (Яндекс и т.п.) не влияет.
add_filter( 'woocommerce_package_rates', function ( $rates ) {
    $s   = get_option( 'cdek_ship_settings', [] );
    $vat = isset( $s['delivery_vat_percent'] ) ? (float) $s['delivery_vat_percent'] : 22.0;
    if ( $vat <= 0 ) {
        return $rates;
    }
    $mult = 1 + ( $vat / 100 );
    foreach ( $rates as $rate ) {
        if ( strpos( (string) $rate->get_method_id(), 'cdek_pvz' ) === false ) {
            continue; // только СДЭК
        }
        $cost = (float) $rate->get_cost();
        if ( $cost > 0 ) {
            $rate->set_cost( (float) ceil( $cost * $mult ) ); // вверх до рубля, без копеек
            $taxes = $rate->get_taxes();
            if ( is_array( $taxes ) && $taxes ) {
                foreach ( $taxes as $k => $t ) { $taxes[ $k ] = (float) $t * $mult; }
                $rate->set_taxes( $taxes );
            }
        }
    }
    return $rates;
}, 100 );


/* ═══════════════════════════════════════════════════════════
 *  4. ADMIN SETTINGS PAGE (API keys, Yandex Maps key)
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
            'account'         => sanitize_text_field( $_POST['account'] ?? '' ),
            'secret'          => sanitize_text_field( $_POST['secret'] ?? '' ),
            'test_mode'       => isset( $_POST['test_mode'] ) ? 'yes' : 'no',
            'ymaps_api_key'   => sanitize_text_field( $_POST['ymaps_api_key'] ?? '' ),
            // Отправитель (склад) — для создания заказов type=2 и вызова курьера
            'sender_company'  => sanitize_text_field( $_POST['sender_company'] ?? '' ),
            'sender_contact'  => sanitize_text_field( $_POST['sender_contact'] ?? '' ),
            'sender_phone'    => sanitize_text_field( $_POST['sender_phone'] ?? '' ),
            'sender_city_code'=> (int) ( $_POST['sender_city_code'] ?? 44 ),
            'sender_postal'   => sanitize_text_field( $_POST['sender_postal'] ?? '' ),
            'sender_address'  => sanitize_text_field( $_POST['sender_address'] ?? '' ),
            'package_comment' => sanitize_text_field( $_POST['package_comment'] ?? 'Книги' ),
            'delivery_vat_percent' => max( 0, (float) str_replace( ',', '.', (string) ( $_POST['delivery_vat_percent'] ?? 22 ) ) ),
        ];
        update_option( 'cdek_ship_settings', $settings );
        echo '<div class="notice notice-success"><p>Настройки сохранены.</p></div>';
    }

    $s = wp_parse_args( get_option( 'cdek_ship_settings', [] ), [
        'account'         => '',
        'secret'          => '',
        'test_mode'       => 'yes',
        'ymaps_api_key'   => '',
        'sender_company'  => '',
        'sender_contact'  => '',
        'sender_phone'    => '',
        'sender_city_code'=> 44,
        'sender_postal'   => '',
        'sender_address'  => '',
        'package_comment' => 'Книги',
        'delivery_vat_percent' => 22,
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
                        <input type="text" name="account" value="<?= esc_attr( $s['account'] ) ?>" class="regular-text">
                        <p class="description">Оставьте пустым для тестового режима (sandbox).</p>
                    </td>
                </tr>
                <tr>
                    <th>Secure (Client Secret)</th>
                    <td>
                        <input type="password" name="secret" value="<?= esc_attr( $s['secret'] ) ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th>Тестовый режим</th>
                    <td>
                        <label>
                            <input type="checkbox" name="test_mode" <?php checked( $s['test_mode'], 'yes' ); ?>>
                            Использовать sandbox API (api.edu.cdek.ru)
                        </label>
                    </td>
                </tr>
                <tr>
                    <th>API-ключ Яндекс.Карт</th>
                    <td>
                        <input type="text" name="ymaps_api_key" value="<?= esc_attr( $s['ymaps_api_key'] ) ?>" class="regular-text">
                        <p class="description">JavaScript API ключ из <a href="https://developer.tech.yandex.ru/" target="_blank">кабинета разработчика Яндекс</a>. Если пустой — карта работает без ключа (с ограничениями).</p>
                    </td>
                </tr>
                <tr><th colspan="2"><h2 style="margin:8px 0">Отправитель (склад)</h2><p class="description" style="font-weight:normal">Нужно для создания заказов и вызова курьера. Заполните по вашему договору СДЭК.</p></th></tr>
                <tr>
                    <th>Компания</th>
                    <td><input type="text" name="sender_company" value="<?= esc_attr( $s['sender_company'] ) ?>" class="regular-text" placeholder="ООО «Ваша компания»"></td>
                </tr>
                <tr>
                    <th>Контактное лицо</th>
                    <td><input type="text" name="sender_contact" value="<?= esc_attr( $s['sender_contact'] ) ?>" class="regular-text" placeholder="Иванов Иван"></td>
                </tr>
                <tr>
                    <th>Телефон склада</th>
                    <td><input type="text" name="sender_phone" value="<?= esc_attr( $s['sender_phone'] ) ?>" class="regular-text" placeholder="+74951234567"></td>
                </tr>
                <tr>
                    <th>Код города СДЭК</th>
                    <td><input type="number" name="sender_city_code" value="<?= esc_attr( $s['sender_city_code'] ) ?>" style="width:120px"> <span class="description">44 = Москва</span></td>
                </tr>
                <tr>
                    <th>Индекс</th>
                    <td><input type="text" name="sender_postal" value="<?= esc_attr( $s['sender_postal'] ) ?>" style="width:120px"></td>
                </tr>
                <tr>
                    <th>Адрес склада</th>
                    <td><input type="text" name="sender_address" value="<?= esc_attr( $s['sender_address'] ) ?>" class="regular-text" placeholder="г. Москва, ул. Примерная, д. 1"></td>
                </tr>
                <tr>
                    <th>Описание груза</th>
                    <td><input type="text" name="package_comment" value="<?= esc_attr( $s['package_comment'] ) ?>" class="regular-text" placeholder="Книги"></td>
                </tr>
                <tr>
                    <th>НДС на доставку, %</th>
                    <td>
                        <input type="number" name="delivery_vat_percent" value="<?= esc_attr( $s['delivery_vat_percent'] ) ?>" min="0" max="100" step="0.1" style="width:120px"> %
                        <p class="description">Наценка НДС к стоимости доставки СДЭК (покупатель видит цену уже с НДС, округление вверх до рубля). 0 — без НДС. По умолчанию 22.</p>
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
        fetch(ajaxurl + '?action=cdek_ship_test_api&_wpnonce=<?= wp_create_nonce( 'cdek_ship_test' ) ?>')
            .then(function(res){ return res.json(); })
            .then(function(data){
                var span = document.createElement('span');
                span.style.color = data.success ? 'green' : 'red';
                span.textContent = (data.success ? '✓ ' : '✗ ') + data.data;
                r.innerHTML = '';
                r.appendChild(span);
            })
            .catch(function(e){
                var span = document.createElement('span');
                span.style.color = 'red';
                span.textContent = '✗ ' + e.message;
                r.innerHTML = '';
                r.appendChild(span);
            });
    });
    </script>
    <?php
}

/* Test API connection */
add_action( 'wp_ajax_cdek_ship_test_api', function () {
    check_ajax_referer( 'cdek_ship_test' );
    try {
        $api   = cdek_ship_get_api();
        $token = $api->get_token();
        $cities = $api->get_cities( [ 'code' => 44 ] );
        $city_name = $cities[0]['city'] ?? 'Unknown';
        wp_send_json_success( "Подключено! Тест: город #44 = {$city_name}" );
    } catch ( \Throwable $e ) {
        wp_send_json_error( $e->getMessage() );
    }
} );


/* ═══════════════════════════════════════════════════════════
 *  5. AJAX ENDPOINTS (frontend)
 * ═══════════════════════════════════════════════════════════ */

/**
 * AJAX: Get CDEK PVZ list for a city.
 */
add_action( 'wp_ajax_cdek_ship_pvz', 'cdek_ship_ajax_pvz' );
add_action( 'wp_ajax_nopriv_cdek_ship_pvz', 'cdek_ship_ajax_pvz' );

function cdek_ship_ajax_pvz(): void {
    check_ajax_referer( 'cdek_ship_nonce', 'nonce' );

    $city     = sanitize_text_field( $_REQUEST['city'] ?? '' );
    $postcode = sanitize_text_field( $_REQUEST['postcode'] ?? '' );

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
 * AJAX: Search CDEK cities (for autocomplete).
 */
add_action( 'wp_ajax_cdek_ship_cities', 'cdek_ship_ajax_cities' );
add_action( 'wp_ajax_nopriv_cdek_ship_cities', 'cdek_ship_ajax_cities' );

function cdek_ship_ajax_cities(): void {
    check_ajax_referer( 'cdek_ship_nonce', 'nonce' );

    $q = sanitize_text_field( $_REQUEST['q'] ?? '' );
    if ( mb_strlen( $q ) < 2 ) {
        wp_send_json_success( [] );
    }

    try {
        $api    = cdek_ship_get_api();
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
        $s = wp_parse_args( get_option( 'cdek_ship_settings', [] ), [
            'account'   => '',
            'secret'    => '',
            'test_mode' => 'yes',
        ] );
        $api = new CDEK_Shipping_API(
            $s['account'],
            $s['secret'],
            $s['test_mode'] === 'yes'
        );
    }
    return $api;
}


/* ═══════════════════════════════════════════════════════════
 *  6. CHECKOUT FRONTEND (scripts + PVZ modal)
 * ═══════════════════════════════════════════════════════════ */

add_action( 'wp_enqueue_scripts', function () {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;

    wp_enqueue_style(
        'cdek-ship-checkout',
        CDEK_SHIP_URL . 'assets/cdek-checkout.css',
        [],
        CDEK_SHIP_VERSION
    );

    $settings = wp_parse_args( get_option( 'cdek_ship_settings', [] ), [
        'ymaps_api_key' => '',
    ] );

    wp_enqueue_script(
        'cdek-ship-checkout',
        CDEK_SHIP_URL . 'assets/cdek-checkout.js',
        [ 'jquery' ],
        CDEK_SHIP_VERSION,
        true
    );

    wp_localize_script( 'cdek-ship-checkout', 'cdekShip', [
        'ajax_url'      => admin_url( 'admin-ajax.php' ),
        'nonce'         => wp_create_nonce( 'cdek_ship_nonce' ),
        'ymaps_api_key' => $settings['ymaps_api_key'],
    ] );
} );


/* ═══════════════════════════════════════════════════════════
 *  7. HIDDEN CHECKOUT FIELDS
 * ═══════════════════════════════════════════════════════════ */

add_action( 'woocommerce_after_order_notes', function () {
    echo '<input type="hidden" name="cdek_pvz_code" id="cdek_pvz_code" value="">';
    echo '<input type="hidden" name="cdek_pvz_address" id="cdek_pvz_address" value="">';
    echo '<input type="hidden" name="cdek_pvz_type" id="cdek_pvz_type" value="">';
} );


/* ═══════════════════════════════════════════════════════════
 *  8. VALIDATE PVZ SELECTION ON CHECKOUT
 * ═══════════════════════════════════════════════════════════ */

add_action( 'woocommerce_checkout_process', function () {
    $is_cdek = false;

    // 1) Надёжнее всего — из POST формы (на этом хуке метод уже отправлен).
    if ( ! empty( $_POST['shipping_method'] ) ) {
        foreach ( (array) wp_unslash( $_POST['shipping_method'] ) as $m ) {
            if ( str_contains( (string) $m, 'cdek_pvz' ) ) { $is_cdek = true; break; }
        }
    }
    // 2) Фолбэк — сессия (если POST по какой-то причине пуст).
    if ( ! $is_cdek ) {
        $chosen_methods = WC()->session ? WC()->session->get( 'chosen_shipping_methods', [] ) : [];
        foreach ( (array) $chosen_methods as $m ) {
            if ( str_contains( (string) $m, 'cdek_pvz' ) ) { $is_cdek = true; break; }
        }
    }

    if ( $is_cdek && empty( $_POST['cdek_pvz_code'] ) ) {
        wc_add_notice( 'Выберите пункт выдачи СДЭК для оформления заказа (кнопка «ВЫБРАТЬ ПВЗ»).', 'error' );
    }
} );


/* ═══════════════════════════════════════════════════════════
 *  9. SAVE PVZ TO ORDER META
 * ═══════════════════════════════════════════════════════════ */

add_action( 'woocommerce_checkout_update_order_meta', function ( $order_id ) {
    if ( ! empty( $_POST['cdek_pvz_code'] ) ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        $order->update_meta_data( '_cdek_pvz_code', sanitize_text_field( $_POST['cdek_pvz_code'] ) );
        $order->update_meta_data( '_cdek_pvz_address', sanitize_text_field( $_POST['cdek_pvz_address'] ?? '' ) );
        $order->update_meta_data( '_cdek_pvz_type', sanitize_text_field( $_POST['cdek_pvz_type'] ?? '' ) );
        $order->save();
    }
} );


/* ═══════════════════════════════════════════════════════════
 *  10. CRON: STATUS SYNC (every 2 hours)
 * ═══════════════════════════════════════════════════════════ */

register_activation_hook( CDEK_SHIP_FILE, function () {
    if ( ! wp_next_scheduled( 'cdek_ship_sync_statuses' ) ) {
        wp_schedule_event( time(), 'cdek_ship_2hours', 'cdek_ship_sync_statuses' );
    }
} );

register_deactivation_hook( CDEK_SHIP_FILE, function () {
    wp_clear_scheduled_hook( 'cdek_ship_sync_statuses' );
} );

add_filter( 'cron_schedules', function ( $schedules ) {
    $schedules['cdek_ship_2hours'] = [
        'interval' => 7200,
        'display'  => 'Каждые 2 часа (СДЭК)',
    ];
    return $schedules;
} );

add_action( 'cdek_ship_sync_statuses', 'cdek_ship_do_sync_statuses' );

function cdek_ship_do_sync_statuses(): void {
    // Terminal statuses — skip these orders
    $terminal = [ 'DELIVERED', 'NOT_DELIVERED', 'INVALID', 'REMOVED' ];

    $orders = wc_get_orders( [
        'limit'      => 50,
        'status'     => [ 'processing', 'on-hold' ],
        'meta_query' => [
            [
                'key'     => '_cdek_order_uuid',
                'compare' => 'EXISTS',
            ],
        ],
        'orderby' => 'date',
        'order'   => 'DESC',
    ] );

    if ( empty( $orders ) ) return;

    try {
        $api = cdek_ship_get_api();
    } catch ( \Throwable $e ) {
        error_log( 'CDEK sync error: ' . $e->getMessage() );
        return;
    }

    foreach ( $orders as $order ) {
        $uuid = $order->get_meta( '_cdek_order_uuid' );
        if ( ! $uuid ) continue;

        $last_code = $order->get_meta( '_cdek_last_status_code' );
        if ( in_array( $last_code, $terminal, true ) ) continue;

        try {
            $info = $api->get_order( $uuid );
            $statuses = $info['entity']['statuses'] ?? [];

            if ( ! empty( $statuses ) ) {
                $latest = $statuses[0]; // first = newest
                $new_code = $latest['code'] ?? '';
                $new_name = $latest['name'] ?? '';

                if ( $new_code && $new_code !== $last_code ) {
                    $order->update_meta_data( '_cdek_last_status_code', $new_code );
                    $order->update_meta_data( '_cdek_last_status', $new_name );
                    $order->update_meta_data( '_cdek_last_status_date', $latest['date_time'] ?? '' );
                    $order->add_order_note( sprintf(
                        'СДЭК: %s (%s)',
                        $new_name,
                        $new_code
                    ) );

                    // Auto-complete on delivery
                    if ( $new_code === 'DELIVERED' ) {
                        $order->update_status( 'completed', 'СДЭК: заказ вручён клиенту.' );
                    }

                    $order->save();
                }
            }

            // Save tracking number (cdek_number)
            $cdek_number = $info['entity']['cdek_number'] ?? '';
            if ( $cdek_number && ! $order->get_meta( '_cdek_tracking_number' ) ) {
                $order->update_meta_data( '_cdek_tracking_number', $cdek_number );
                $order->save();
            }

        } catch ( \Throwable $e ) {
            // silently skip
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( "CDEK sync #{$order->get_id()}: " . $e->getMessage() );
            }
        }
    }
}


/* ═══════════════════════════════════════════════════════════
 *  11. ADMIN: ORDER STATUS COLUMN
 * ═══════════════════════════════════════════════════════════ */

add_filter( 'manage_edit-shop_order_columns', 'cdek_ship_add_order_column' );
add_filter( 'manage_woocommerce_page_wc-orders_columns', 'cdek_ship_add_order_column' );

function cdek_ship_add_order_column( $columns ) {
    $new = [];
    foreach ( $columns as $key => $label ) {
        $new[ $key ] = $label;
        if ( $key === 'order_status' ) {
            $new['cdek_status'] = 'СДЭК';
        }
    }
    return $new;
}

add_action( 'manage_shop_order_posts_custom_column', 'cdek_ship_render_order_column', 10, 2 );
add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'cdek_ship_render_order_column_hpos', 10, 2 );

function cdek_ship_render_order_column( $column, $post_id ) {
    if ( $column !== 'cdek_status' ) return;
    $order = wc_get_order( $post_id );
    if ( ! $order ) return;
    cdek_ship_echo_status_badge( $order );
}

function cdek_ship_render_order_column_hpos( $column, $order ) {
    if ( $column !== 'cdek_status' ) return;
    cdek_ship_echo_status_badge( $order );
}

function cdek_ship_echo_status_badge( $order ): void {
    $code = $order->get_meta( '_cdek_last_status_code' );
    $name = $order->get_meta( '_cdek_last_status' );
    $pvz  = $order->get_meta( '_cdek_pvz_code' );

    if ( ! $pvz && ! $code ) {
        echo '—';
        return;
    }

    if ( ! $code ) {
        echo '<span style="color:#999">ПВЗ: ' . esc_html( $pvz ) . '</span>';
        return;
    }

    $colors = [
        'CREATED'   => '#2196F3',
        'ACCEPTED'  => '#2196F3',
        'RECEIVED'  => '#FF9800',
        'READY'     => '#4CAF50',
        'DELIVERED' => '#4CAF50',
        'INVALID'   => '#f44336',
        'REMOVED'   => '#f44336',
    ];

    // Determine category for color
    $color = '#999';
    foreach ( $colors as $prefix => $c ) {
        if ( str_starts_with( $code, $prefix ) || str_contains( $code, $prefix ) ) {
            $color = $c;
            break;
        }
    }

    printf(
        '<span style="color:%s;font-size:12px;font-weight:600" title="%s">%s</span>',
        esc_attr( $color ),
        esc_attr( $code ),
        esc_html( mb_strimwidth( $name ?: $code, 0, 25, '…' ) )
    );
}
