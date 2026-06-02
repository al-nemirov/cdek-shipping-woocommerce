<?php
/**
 * СДЭК Hub — единая страница управления отправками.
 * Объединяет: список заказов, вызов курьера, настройки.
 * Убирает из меню WooCommerce лишние пункты.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CDEK_Hub {

    const SLUG = 'cdek-hub';

    public static function init(): void {
        $self = new self();
        add_action( 'admin_menu', [ $self, 'register_menu' ], 55 );
        add_action( 'admin_menu', [ $self, 'cleanup_menu' ], 9999 );
        add_action( 'wp_ajax_cdek_orders_data',  [ 'CDEK_Orders_Page', 'ajax_orders_static' ] );
        add_action( 'wp_ajax_cdek_quick_action', [ 'CDEK_Orders_Page', 'ajax_quick_action_static' ] );
        add_action( 'admin_post_cdek_create_intake', [ new CDEK_Intake_Admin(), 'handle_create' ] );
        add_action( 'admin_post_cdek_cancel_intake', [ new CDEK_Intake_Admin(), 'handle_cancel' ] );
        add_action( 'admin_enqueue_scripts', [ $self, 'enqueue' ] );
    }

    /* ── Меню ─────────────────────────────────────────────────── */

    public function register_menu(): void {
        add_submenu_page(
            'woocommerce',
            '📦 Отправка',
            '📦 Отправка',
            'manage_woocommerce',
            self::SLUG,
            [ $this, 'render' ]
        );
    }

    /** Убираем лишние пункты из меню WooCommerce. */
    public function cleanup_menu(): void {
        // Наши старые отдельные пункты (теперь всё в Hub)
        remove_submenu_page( 'woocommerce', 'cdek-shipping' );
        remove_submenu_page( 'woocommerce', 'cdek-orders' );
        remove_submenu_page( 'woocommerce', 'cdek-intake' );

        // WooCommerce — служебные, не нужны сотруднику
        remove_submenu_page( 'woocommerce', 'wc-status' );            // Статус
        remove_submenu_page( 'woocommerce', 'wc-addons' );            // Расширения (старый slug)
        remove_submenu_page( 'woocommerce', 'coupons-moved' );        // Купоны (moved page)
        remove_submenu_page( 'woocommerce', 'wc-reports' );           // Отчёты

        // Яндекс Доставка — технические страницы
        remove_submenu_page( 'woocommerce', 'yandex-dostavka-settings' );
        remove_submenu_page( 'woocommerce', 'yandex-dostavka-moysklad' );
        remove_submenu_page( 'woocommerce', 'yandex-dostavka-api-console' );
        remove_submenu_page( 'woocommerce', 'yandex-dostavka-api' );
    }

    public function enqueue( string $hook ): void {
        if ( strpos( $hook, self::SLUG ) === false ) {
            return;
        }
        wp_enqueue_style( 'cdek-orders-page',  CDEK_SHIP_URL . 'assets/cdek-orders-page.css',  [], CDEK_SHIP_VERSION );
        wp_enqueue_style( 'cdek-hub',           CDEK_SHIP_URL . 'assets/cdek-hub.css',          [], CDEK_SHIP_VERSION );
        wp_enqueue_script( 'cdek-orders-page', CDEK_SHIP_URL . 'assets/cdek-orders-page.js', [ 'jquery' ], CDEK_SHIP_VERSION, true );
        wp_localize_script( 'cdek-orders-page', 'cdekOrders', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'cdek_orders_nonce' ),
        ] );
    }

    /* ── Страница ─────────────────────────────────────────────── */

    public function render(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Нет доступа' );
        }

        $tab     = sanitize_key( $_GET['tab'] ?? 'orders' );
        $allowed = [ 'orders', 'courier', 'settings' ];
        if ( ! in_array( $tab, $allowed, true ) ) {
            $tab = 'orders';
        }

        $tab_url = function( string $t ): string {
            return admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $t );
        };

        $notice = isset( $_GET['cdek_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['cdek_msg'] ) ) : '';
        $err    = isset( $_GET['cdek_err'] ) ? sanitize_text_field( wp_unslash( $_GET['cdek_err'] ) ) : '';
        ?>
        <div class="wrap cdek-hub-wrap">

            <?php if ( $notice ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
            <?php endif; ?>
            <?php if ( $err ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html( $err ); ?></p></div>
            <?php endif; ?>

            <nav class="cdek-hub-tabs">
                <a href="<?php echo esc_url( $tab_url( 'orders' ) ); ?>"
                   class="cdek-hub-tab <?php echo $tab === 'orders' ? 'active' : ''; ?>">
                    📦 Заказы
                </a>
                <a href="<?php echo esc_url( $tab_url( 'courier' ) ); ?>"
                   class="cdek-hub-tab <?php echo $tab === 'courier' ? 'active' : ''; ?>">
                    🚚 Курьер
                </a>
                <a href="<?php echo esc_url( $tab_url( 'settings' ) ); ?>"
                   class="cdek-hub-tab <?php echo $tab === 'settings' ? 'active' : ''; ?>">
                    ⚙️ Настройки
                </a>
            </nav>

            <div class="cdek-hub-body">
                <?php
                match ( $tab ) {
                    'orders'   => CDEK_Orders_Page::render_static(),
                    'courier'  => CDEK_Intake_Admin::render_static(),
                    'settings' => $this->render_settings(),
                    default    => CDEK_Orders_Page::render_static(),
                };
                ?>
            </div>
        </div>
        <?php
    }

    /* ── Настройки (перенесены из cdek-shipping-woocommerce.php) ── */

    private function render_settings(): void {
        if ( isset( $_POST['cdek_ship_save'] ) && check_admin_referer( 'cdek_ship_nonce' ) ) {
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                wp_die( 'Нет доступа' );
            }
            $settings = [
                'account'         => sanitize_text_field( $_POST['account'] ?? '' ),
                'secret'          => sanitize_text_field( $_POST['secret'] ?? '' ),
                'test_mode'       => isset( $_POST['test_mode'] ) ? 'yes' : 'no',
                'ymaps_api_key'   => sanitize_text_field( $_POST['ymaps_api_key'] ?? '' ),
                'sender_company'  => sanitize_text_field( $_POST['sender_company'] ?? '' ),
                'sender_contact'  => sanitize_text_field( $_POST['sender_contact'] ?? '' ),
                'sender_phone'    => sanitize_text_field( $_POST['sender_phone'] ?? '' ),
                'sender_city_code'=> (int) ( $_POST['sender_city_code'] ?? 44 ),
                'sender_postal'   => sanitize_text_field( $_POST['sender_postal'] ?? '' ),
                'sender_address'  => sanitize_text_field( $_POST['sender_address'] ?? '' ),
                'package_comment' => sanitize_text_field( $_POST['package_comment'] ?? 'Книги' ),
            ];
            update_option( 'cdek_ship_settings', $settings );
            echo '<div class="notice notice-success inline"><p>Настройки сохранены.</p></div>';
        }

        $s = wp_parse_args( get_option( 'cdek_ship_settings', [] ), [
            'account' => '', 'secret' => '', 'test_mode' => 'yes', 'ymaps_api_key' => '',
            'sender_company' => '', 'sender_contact' => '', 'sender_phone' => '',
            'sender_city_code' => 44, 'sender_postal' => '', 'sender_address' => '',
            'package_comment' => 'Книги',
        ] );
        ?>
        <h2>СДЭК — Настройки API</h2>
        <form method="post">
            <?php wp_nonce_field( 'cdek_ship_nonce' ); ?>
            <table class="form-table" style="max-width:680px">
                <tr><th colspan="2"><h3 style="margin:4px 0">Ключи API</h3></th></tr>
                <tr>
                    <th>Account (Client ID)</th>
                    <td><input type="text" name="account" value="<?= esc_attr( $s['account'] ) ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th>Secure (Client Secret)</th>
                    <td><input type="password" name="secret" value="<?= esc_attr( $s['secret'] ) ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th>Тестовый режим</th>
                    <td><label><input type="checkbox" name="test_mode" <?php checked( $s['test_mode'], 'yes' ); ?>> api.edu.cdek.ru (sandbox)</label></td>
                </tr>
                <tr>
                    <th>API-ключ Яндекс.Карт</th>
                    <td><input type="text" name="ymaps_api_key" value="<?= esc_attr( $s['ymaps_api_key'] ) ?>" class="regular-text">
                        <p class="description"><a href="https://developer.tech.yandex.ru/" target="_blank">developer.tech.yandex.ru</a></p></td>
                </tr>
                <tr><th colspan="2"><h3 style="margin:16px 0 4px">Отправитель (склад)</h3></th></tr>
                <tr>
                    <th>Компания</th>
                    <td><input type="text" name="sender_company" value="<?= esc_attr( $s['sender_company'] ) ?>" class="regular-text" placeholder="ООО «Ваша компания»"></td>
                </tr>
                <tr>
                    <th>Контактное лицо</th>
                    <td><input type="text" name="sender_contact" value="<?= esc_attr( $s['sender_contact'] ) ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th>Телефон склада</th>
                    <td><input type="text" name="sender_phone" value="<?= esc_attr( $s['sender_phone'] ) ?>" class="regular-text" placeholder="+74951234567"></td>
                </tr>
                <tr>
                    <th>Код города СДЭК</th>
                    <td><input type="number" name="sender_city_code" value="<?= esc_attr( $s['sender_city_code'] ) ?>" style="width:100px"> <span class="description">44 = Москва</span></td>
                </tr>
                <tr>
                    <th>Индекс</th>
                    <td><input type="text" name="sender_postal" value="<?= esc_attr( $s['sender_postal'] ) ?>" style="width:100px"></td>
                </tr>
                <tr>
                    <th>Адрес склада</th>
                    <td><input type="text" name="sender_address" value="<?= esc_attr( $s['sender_address'] ) ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th>Описание груза</th>
                    <td><input type="text" name="package_comment" value="<?= esc_attr( $s['package_comment'] ) ?>" class="regular-text" placeholder="Книги"></td>
                </tr>
            </table>
            <p><input type="submit" name="cdek_ship_save" class="button button-primary" value="Сохранить настройки"></p>
        </form>
        <?php
    }
}
