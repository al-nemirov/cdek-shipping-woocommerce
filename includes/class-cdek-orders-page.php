<?php
/**
 * СДЭК — Страница заказов.
 * Чистый список заказов WooCommerce с фокусом на отправку:
 * статусы СДЭК, МС, ЯД, быстрые действия без лишнего интерфейса.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CDEK_Orders_Page {

    // Только AJAX — меню регистрирует Hub.
    public static function init(): void {}

    // Статические обёртки для Hub.
    public static function render_static(): void      { ( new self() )->render(); }
    public static function ajax_orders_static(): void { ( new self() )->ajax_orders(); }
    public static function ajax_quick_action_static(): void { ( new self() )->ajax_quick_action(); }

    /* ── Страница ─────────────────────────────────────────────── */

    public function render(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Нет доступа' );
        }
        ?>
        <div class="wrap cdek-op-wrap">
            <h1 class="cdek-op-title">📦 Заказы — Отправка</h1>

            <!-- Сводка -->
            <div class="cdek-op-stats" id="cdek-op-stats">
                <div class="cdek-op-stat" data-filter="today">
                    <strong id="stat-today">…</strong>
                    <span>сегодня</span>
                </div>
                <div class="cdek-op-stat cdek-op-stat--warn" data-filter="need_cdek">
                    <strong id="stat-need-cdek">…</strong>
                    <span>ждут отправки СДЭК</span>
                </div>
                <div class="cdek-op-stat cdek-op-stat--ok" data-filter="cdek_sent">
                    <strong id="stat-cdek-sent">…</strong>
                    <span>в СДЭК</span>
                </div>
                <div class="cdek-op-stat" data-filter="need_ms">
                    <strong id="stat-need-ms">…</strong>
                    <span>ждут МС</span>
                </div>
            </div>

            <!-- Фильтры -->
            <div class="cdek-op-filters">
                <button class="cdek-op-filter active" data-filter="">Все</button>
                <button class="cdek-op-filter" data-filter="today">Сегодня</button>
                <button class="cdek-op-filter" data-filter="cdek">СДЭК</button>
                <button class="cdek-op-filter" data-filter="yd">Яндекс</button>
                <button class="cdek-op-filter" data-filter="need_cdek">Ждут СДЭК</button>
                <button class="cdek-op-filter" data-filter="cdek_sent">Отправлено СДЭК</button>
                <input type="search" id="cdek-op-search" placeholder="Поиск: #номер, покупатель…" class="cdek-op-search">
            </div>

            <!-- Таблица -->
            <div class="cdek-op-table-wrap">
                <table class="cdek-op-table" id="cdek-op-table">
                    <thead>
                    <tr>
                        <th>Заказ</th>
                        <th>Дата</th>
                        <th>Покупатель</th>
                        <th>Товары</th>
                        <th>Сумма</th>
                        <th>Доставка</th>
                        <th>СДЭК</th>
                        <th>МойСклад</th>
                        <th>Действия</th>
                    </tr>
                    </thead>
                    <tbody id="cdek-op-tbody">
                    <tr><td colspan="9" class="cdek-op-loading">Загрузка…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /* ── AJAX: список заказов ─────────────────────────────────── */

    public function ajax_orders(): void {
        check_ajax_referer( 'cdek_orders_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'no_access' );
        }

        $filter = sanitize_text_field( wp_unslash( $_POST['filter'] ?? '' ) );
        $search = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );
        $today  = gmdate( 'Y-m-d' );

        $args = [
            'limit'   => 80,
            'orderby' => 'date',
            'order'   => 'DESC',
            'status'  => array_keys( wc_get_order_statuses() ),
        ];

        // Поиск по номеру
        if ( $search !== '' && ctype_digit( ltrim( $search, '#' ) ) ) {
            $args['limit'] = 1;
            // WC не поддерживает поиск по номеру напрямую — ищем через get_order
            $oid = (int) ltrim( $search, '#' );
            $o   = wc_get_order( $oid );
            $orders = $o ? [ $o ] : [];
        } else {
            $orders = wc_get_orders( $args );
        }

        $rows   = [];
        $counts = [ 'today' => 0, 'need_cdek' => 0, 'cdek_sent' => 0, 'need_ms' => 0 ];

        foreach ( $orders as $o ) {
            if ( ! $o instanceof WC_Order ) {
                continue;
            }

            $oid      = $o->get_id();
            $odate    = $o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d' ) : '';
            $is_today = ( $odate === $today );

            // Способ доставки
            $method_id = '';
            $method_name = '';
            foreach ( $o->get_shipping_methods() as $m ) {
                $method_id   = $m->get_method_id();
                $method_name = $m->get_name();
                break;
            }
            $is_cdek = ( $method_id === 'cdek_pvz' );
            $is_yd   = str_starts_with( $method_id, 'yd_' );

            // Мета СДЭК / МС
            $cdek_uuid   = $o->get_meta( '_cdek_order_uuid' );
            $cdek_status = $o->get_meta( '_cdek_last_status' );
            $cdek_track  = $o->get_meta( '_cdek_track_number' );
            $ms_id       = $o->get_meta( 'wc_ms_order_id' ) ?: $o->get_meta( 'yd_moysklad_id' );
            $ms_name     = $o->get_meta( 'wc_ms_order_name' );
            $ms_error    = $o->get_meta( 'wc_ms_error' );

            // Счётчики
            if ( $is_today ) {
                $counts['today']++;
            }
            if ( $is_cdek && ! $cdek_uuid ) {
                $counts['need_cdek']++;
            }
            if ( $is_cdek && $cdek_uuid ) {
                $counts['cdek_sent']++;
            }
            if ( ! $ms_id ) {
                $counts['need_ms']++;
            }

            // Фильтрация
            if ( $filter === 'today' && ! $is_today ) {
                continue;
            }
            if ( $filter === 'cdek' && ! $is_cdek ) {
                continue;
            }
            if ( $filter === 'yd' && ! $is_yd ) {
                continue;
            }
            if ( $filter === 'need_cdek' && ! ( $is_cdek && ! $cdek_uuid ) ) {
                continue;
            }
            if ( $filter === 'cdek_sent' && ! ( $is_cdek && $cdek_uuid ) ) {
                continue;
            }
            if ( $filter === 'need_ms' && $ms_id ) {
                continue;
            }

            // Поиск по имени
            if ( $search !== '' && ! ctype_digit( ltrim( $search, '#' ) ) ) {
                $hay = mb_strtolower( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() . ' ' . $o->get_billing_email() );
                if ( mb_strpos( $hay, mb_strtolower( $search ) ) === false ) {
                    continue;
                }
            }

            // Товары
            $items = [];
            foreach ( $o->get_items() as $item ) {
                $items[] = mb_substr( $item->get_name(), 0, 35 ) . ' ×' . $item->get_quantity();
            }

            // Статус WC
            $status_label = wc_get_order_status_name( $o->get_status() );

            // URL редактирования
            $edit_url = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $oid );
            if ( ! function_exists( 'wc_get_page_screen_id' ) ) {
                $edit_url = get_edit_post_link( $oid );
            }

            $rows[] = [
                'id'           => $oid,
                'number'       => $o->get_order_number(),
                'edit_url'     => $edit_url,
                'date'         => $o->get_date_created() ? $o->get_date_created()->date( 'd.m H:i' ) : '',
                'is_today'     => $is_today,
                'customer'     => trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ),
                'phone'        => $o->get_billing_phone(),
                'items'        => $items,
                'total'        => wc_price( $o->get_total() ),
                'status'       => $o->get_status(),
                'status_label' => $status_label,
                'method_id'    => $method_id,
                'method_name'  => $method_name,
                'is_cdek'      => $is_cdek,
                'is_yd'        => $is_yd,
                'cdek_uuid'    => $cdek_uuid,
                'cdek_status'  => $cdek_status,
                'cdek_track'   => $cdek_track,
                'ms_id'        => $ms_id,
                'ms_name'      => $ms_name,
                'ms_error'     => $ms_error,
            ];
        }

        wp_send_json_success( [
            'rows'   => $rows,
            'counts' => $counts,
        ] );
    }

    /* ── AJAX: быстрые действия ───────────────────────────────── */

    public function ajax_quick_action(): void {
        check_ajax_referer( 'cdek_orders_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_send_json_error( 'no_access' );
        }

        $action = sanitize_text_field( wp_unslash( $_POST['cdek_action'] ?? '' ) );
        $oid    = absint( $_POST['order_id'] ?? 0 );
        $order  = $oid ? wc_get_order( $oid ) : null;

        if ( ! $order ) {
            wp_send_json_error( 'Заказ не найден' );
        }

        switch ( $action ) {

            case 'send_cdek':
                // Использует метод из class-cdek-admin.php
                if ( class_exists( 'CDEK_Shipping_Admin' ) ) {
                    $admin = new CDEK_Shipping_Admin();
                    $ref   = new ReflectionClass( $admin );
                    $m     = $ref->getMethod( 'create_cdek_order' );
                    $m->setAccessible( true );
                    $m->invoke( $admin, $order );
                    $order = wc_get_order( $oid );
                    $uuid  = $order->get_meta( '_cdek_order_uuid' );
                    if ( $uuid ) {
                        wp_send_json_success( [
                            'msg'  => 'Отправлено в СДЭК',
                            'uuid' => $uuid,
                            'status' => $order->get_meta( '_cdek_last_status' ),
                        ] );
                    } else {
                        wp_send_json_error( $order->get_meta( '_cdek_ship_error' ) ?: 'Ошибка отправки в СДЭК' );
                    }
                } else {
                    wp_send_json_error( 'CDEK Admin class not found' );
                }
                break;

            case 'send_ms':
                if ( class_exists( 'WC_MoySklad_Sync' ) ) {
                    WC_MS_Order::sync_order( $order );
                    $order  = wc_get_order( $oid );
                    $ms_id  = $order->get_meta( 'wc_ms_order_id' );
                    $ms_err = $order->get_meta( 'wc_ms_error' );
                    if ( $ms_id ) {
                        wp_send_json_success( [ 'msg' => 'Отправлено в МС', 'ms_id' => $ms_id, 'ms_name' => $order->get_meta( 'wc_ms_order_name' ) ] );
                    } else {
                        wp_send_json_error( $ms_err ?: 'Ошибка МС' );
                    }
                } else {
                    wp_send_json_error( 'МС-плагин не активен' );
                }
                break;

            case 'update_cdek':
                // Обновить статус СДЭК вручную — дёргаем метод из admin
                if ( class_exists( 'CDEK_Shipping_Admin' ) ) {
                    $admin = new CDEK_Shipping_Admin();
                    $ref   = new ReflectionClass( $admin );
                    if ( $ref->hasMethod( 'refresh_status' ) ) {
                        $m = $ref->getMethod( 'refresh_status' );
                        $m->setAccessible( true );
                        $m->invoke( $admin, $order );
                    }
                    $order = wc_get_order( $oid );
                    wp_send_json_success( [
                        'msg'    => 'Статус обновлён',
                        'status' => $order->get_meta( '_cdek_last_status' ),
                        'track'  => $order->get_meta( '_cdek_tracking_number' ),
                    ] );
                } else {
                    wp_send_json_error( 'CDEK Admin class not found' );
                }
                break;

            default:
                wp_send_json_error( 'Неизвестное действие' );
        }
    }
}
