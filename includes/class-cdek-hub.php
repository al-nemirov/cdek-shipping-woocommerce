<?php
/**
 * СДЭК: регистрация страницы «Курьер» (вызов курьера).
 *
 * ВАЖНО: консолидацию меню (скрыть/встроить в единый хаб) теперь делает отдельный
 * универсальный плагин «Admin Menu Hub». Здесь — только регистрация родных страниц
 * СДЭК как обычных подпунктов WooCommerce, чтобы их можно было встроить/скрыть из UI
 * того плагина. Настройки СДЭК регистрируются в основном файле плагина (slug
 * `cdek-shipping`), здесь — страница курьера (slug `cdek-intake`).
 *
 * Имя класса оставлено CDEK_Hub для совместимости с вызовом CDEK_Hub::init().
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CDEK_Hub {

    const SLUG = 'cdek-intake';

    public static function init(): void {
        $self = new self();
        add_action( 'admin_menu', [ $self, 'register_menu' ], 56 );
        add_action( 'admin_post_cdek_create_intake', [ new CDEK_Intake_Admin(), 'handle_create' ] );
        add_action( 'admin_post_cdek_cancel_intake', [ new CDEK_Intake_Admin(), 'handle_cancel' ] );
        add_action( 'admin_enqueue_scripts', [ $self, 'enqueue' ] );
    }

    public function register_menu(): void {
        add_submenu_page(
            'woocommerce',
            'Вызов курьера СДЭК',
            '🚚 Курьер СДЭК',
            'manage_woocommerce',
            self::SLUG,
            [ 'CDEK_Intake_Admin', 'render_static' ]
        );
    }

    public function enqueue( string $hook ): void {
        if ( strpos( $hook, self::SLUG ) === false ) {
            return;
        }
        wp_enqueue_style( 'cdek-hub', CDEK_SHIP_URL . 'assets/cdek-hub.css', [], CDEK_SHIP_VERSION );
    }
}
