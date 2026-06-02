<?php
/**
 * СДЭК — Вызов курьера (заявка на забор заказов за день).
 * Страница: WooCommerce → СДЭК Курьер.
 * Один забор на выбранные СДЭК-заказы. Чеклист: сегодняшние + старые, ещё не переданные курьеру.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CDEK_Intake_Admin {

    const OPT_LAST  = 'cdek_ship_last_intake'; // данные последней заявки
    const META_DONE = '_cdek_intake_done';     // заказ передан курьеру (= номер заявки)

    // Только хуки admin_post — меню и AJAX регистрирует Hub.
    public static function init(): void {}

    // Статическая обёртка для Hub.
    public static function render_static(): void { ( new self() )->render(); }

    private function time_presets(): array {
        return [
            '09:00-18:00' => 'Рабочий день 09:00–18:00',
            '10:00-14:00' => 'Утро 10:00–14:00',
            '14:00-18:00' => 'День 14:00–18:00',
            '18:00-22:00' => 'Вечер 18:00–22:00',
            '22:00-06:00' => 'Ночной 22:00–06:00',
        ];
    }

    /** СДЭК-заказы, ещё не переданные курьеру (свежие сверху). */
    private function pending_orders(): array {
        $list   = [];
        $orders = wc_get_orders( [
            'limit'   => 100,
            'orderby' => 'date',
            'order'   => 'DESC',
            'status'  => array_keys( wc_get_order_statuses() ),
        ] );
        foreach ( $orders as $o ) {
            if ( ! $o instanceof WC_Order ) {
                continue;
            }
            $is_cdek = false;
            foreach ( $o->get_shipping_methods() as $m ) {
                if ( $m->get_method_id() === 'cdek_pvz' ) {
                    $is_cdek = true;
                    break;
                }
            }
            if ( ! $is_cdek || $o->get_meta( self::META_DONE ) ) {
                continue;
            }
            $list[] = $o;
        }
        return $list;
    }

    /** Вес заказа в кг (сумма весов товаров, по умолчанию 0.5 кг/шт). */
    private function order_weight_kg( WC_Order $o ): float {
        $g = 0;
        foreach ( $o->get_items() as $item ) {
            $p   = $item->get_product();
            $qty = max( 1, (int) $item->get_quantity() );
            $w   = $p ? (float) $p->get_weight() : 0;
            $unit = get_option( 'woocommerce_weight_unit', 'kg' );
            $w_g = $w > 0 ? wc_get_weight( $w, 'g', $unit ) : 500;
            $g  += $w_g * $qty;
        }
        return round( $g / 1000, 1 );
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Недостаточно прав' );
        }
        $s        = get_option( 'cdek_ship_settings', [] );
        $last     = get_option( self::OPT_LAST, [] );
        $tomorrow = gmdate( 'Y-m-d', time() + 86400 );
        $notice   = isset( $_GET['cdek_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['cdek_msg'] ) ) : '';
        $err      = isset( $_GET['cdek_err'] ) ? sanitize_text_field( wp_unslash( $_GET['cdek_err'] ) ) : '';
        $orders   = $this->pending_orders();
        $today    = gmdate( 'Y-m-d' );

        // авто-вес по сегодняшним заказам (можно поправить вручную)
        $default_weight = 0.0;
        foreach ( $orders as $o ) {
            if ( $o->get_date_created() && $o->get_date_created()->date( 'Y-m-d' ) === $today ) {
                $default_weight += $this->order_weight_kg( $o );
            }
        }
        $default_weight = max( 1, (int) ceil( $default_weight ) );
        ?>
        <div class="wrap">
            <h1>СДЭК — Вызов курьера</h1>
            <p>Один забор на выбранные заказы. Курьер приедет на склад и заберёт отмеченные накладные СДЭК.</p>

            <?php if ( $notice ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>
            <?php if ( $err ) : ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html( $err ); ?></p></div><?php endif; ?>

            <?php if ( ! empty( $last['number'] ) ) : ?>
            <div class="card" style="max-width:760px;padding:12px 18px;margin-bottom:18px;background:#f6fff6;border-left:4px solid #46b450">
                <strong>Текущая заявка:</strong> № <?php echo esc_html( $last['number'] ); ?> на <?php echo esc_html( $last['date'] ?? '' ); ?>
                <?php if ( ! empty( $last['orders'] ) ) : ?>(заказов: <?php echo (int) count( $last['orders'] ); ?>)<?php endif; ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:12px" onsubmit="return confirm('Отменить заявку?');">
                    <?php wp_nonce_field( 'cdek_cancel_intake' ); ?>
                    <input type="hidden" name="action" value="cdek_cancel_intake">
                    <input type="hidden" name="uuid" value="<?php echo esc_attr( $last['uuid'] ?? '' ); ?>">
                    <button class="button button-secondary">Отменить заявку</button>
                </form>
            </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'cdek_create_intake' ); ?>
                <input type="hidden" name="action" value="cdek_create_intake">

                <h2>Заказы для забора</h2>
                <?php if ( ! $orders ) : ?>
                    <p><em>Нет СДЭК-заказов, ожидающих курьера.</em></p>
                <?php else : ?>
                <p>
                    <a href="#" class="button button-small" id="cdek-check-today">Только сегодняшние</a>
                    <a href="#" class="button button-small" id="cdek-check-all">Выбрать все</a>
                    <a href="#" class="button button-small" id="cdek-check-none">Снять все</a>
                </p>
                <table class="wp-list-table widefat fixed striped" style="max-width:900px">
                    <thead><tr>
                        <td style="width:34px"><input type="checkbox" id="cdek-toggle-all"></td>
                        <th>Заказ</th><th>Дата</th><th>Статус</th><th>Куда (ПВЗ)</th><th>Вес</th><th>Накладная</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $orders as $o ) :
                        $oid     = $o->get_id();
                        $odate   = $o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d' ) : '';
                        $is_today = ( $odate === $today );
                        $wkg     = $this->order_weight_kg( $o );
                        $pvz     = $o->get_meta( '_cdek_pvz_address' ) ?: $o->get_meta( '_cdek_pvz_code' ) ?: $o->get_billing_city();
                        $has_lbl = (bool) $o->get_meta( '_cdek_order_uuid' );
                    ?>
                        <tr>
                            <td><input type="checkbox" class="cdek-order-cb" name="order_ids[]" value="<?php echo esc_attr( $oid ); ?>"
                                       data-today="<?php echo $is_today ? '1' : '0'; ?>" data-weight="<?php echo esc_attr( $wkg ); ?>"
                                       <?php checked( $is_today ); ?>></td>
                            <td><a href="<?php echo esc_url( $o->get_edit_order_url() ); ?>" target="_blank">#<?php echo esc_html( $o->get_order_number() ); ?></a></td>
                            <td><?php echo esc_html( $odate ); ?><?php echo $is_today ? ' <span style="color:#46b450">(сегодня)</span>' : ''; ?></td>
                            <td><?php echo esc_html( wc_get_order_status_name( $o->get_status() ) ); ?></td>
                            <td style="font-size:12px"><?php echo esc_html( mb_substr( (string) $pvz, 0, 40 ) ); ?></td>
                            <td><?php echo esc_html( $wkg ); ?> кг</td>
                            <td><?php echo $has_lbl ? '✅ есть' : '<span style="color:#d63638">нет — отправьте в СДЭК</span>'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <h2 style="margin-top:22px">Параметры забора</h2>
                <table class="form-table" style="max-width:640px">
                    <tr><th><label for="intake_date">Дата забора</label></th>
                        <td><input type="date" id="intake_date" name="intake_date" value="<?php echo esc_attr( $tomorrow ); ?>" min="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" required>
                            <p class="description">Завтра, послезавтра или другой день.</p></td></tr>
                    <tr><th><label for="intake_time">Интервал</label></th>
                        <td><select id="intake_time" name="intake_time">
                            <?php foreach ( $this->time_presets() as $val => $label ) : ?><option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
                        </select></td></tr>
                    <tr><th><label for="intake_weight">Общий вес, кг</label></th>
                        <td><input type="number" id="intake_weight" name="intake_weight" value="<?php echo esc_attr( $default_weight ); ?>" min="1" step="1" style="width:90px">
                            <p class="description">Считается автоматически по выбранным заказам, можно поправить.</p></td></tr>
                    <tr><th><label for="intake_comment">Комментарий</label></th>
                        <td><input type="text" id="intake_comment" name="intake_comment" value="Книги, забор заказов интернет-магазина" class="regular-text"></td></tr>
                </table>
                <p><strong>Откуда заберёт курьер:</strong> <?php echo esc_html( $s['sender_address'] ?? 'не задан адрес склада' ); ?>, тел. <?php echo esc_html( $s['sender_phone'] ?? '—' ); ?></p>
                <p><button type="submit" class="button button-primary button-hero">📦 Сформировать заявку на курьера</button></p>
            </form>
        </div>
        <script>
        (function(){
            var cbs=function(){return Array.prototype.slice.call(document.querySelectorAll('.cdek-order-cb'));};
            var weight=document.getElementById('intake_weight');
            function recalc(){var t=0;cbs().forEach(function(c){if(c.checked)t+=parseFloat(c.dataset.weight||0);});if(weight)weight.value=Math.max(1,Math.ceil(t));}
            function setAll(fn){cbs().forEach(fn);recalc();}
            document.addEventListener('change',function(e){if(e.target.classList&&e.target.classList.contains('cdek-order-cb'))recalc();});
            var ta=document.getElementById('cdek-toggle-all');if(ta)ta.addEventListener('change',function(){setAll(function(c){c.checked=ta.checked;});});
            var bt=document.getElementById('cdek-check-today');if(bt)bt.addEventListener('click',function(e){e.preventDefault();setAll(function(c){c.checked=c.dataset.today==='1';});});
            var ba=document.getElementById('cdek-check-all');if(ba)ba.addEventListener('click',function(e){e.preventDefault();setAll(function(c){c.checked=true;});});
            var bn=document.getElementById('cdek-check-none');if(bn)bn.addEventListener('click',function(e){e.preventDefault();setAll(function(c){c.checked=false;});});
        })();
        </script>
        <?php
    }

    public function handle_create(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'cdek_create_intake' ) ) {
            wp_die( 'Доступ запрещён' );
        }
        $s        = get_option( 'cdek_ship_settings', [] );
        $date     = sanitize_text_field( wp_unslash( $_POST['intake_date'] ?? '' ) );
        $time     = sanitize_text_field( wp_unslash( $_POST['intake_time'] ?? '09:00-18:00' ) );
        $weight   = max( 1, (int) ( $_POST['intake_weight'] ?? 5 ) );
        $comment  = sanitize_text_field( wp_unslash( $_POST['intake_comment'] ?? 'Книги' ) );
        $order_ids = array_map( 'absint', (array) ( $_POST['order_ids'] ?? [] ) );
        $order_ids = array_values( array_filter( $order_ids ) );

        [ $from, $to ] = array_pad( explode( '-', $time ), 2, '18:00' );

        $payload = [
            'intake_date'      => $date,
            'intake_time_from' => trim( $from ),
            'intake_time_to'   => trim( $to ),
            'name'             => $comment ?: 'Книги',
            'weight'           => $weight * 1000,
            'comment'          => $comment,
            'sender'           => [
                'company' => $s['sender_company'] ?? get_bloginfo( 'name' ),
                'name'    => $s['sender_contact'] ?? ( $s['sender_company'] ?? get_bloginfo( 'name' ) ),
                'phones'  => [ [ 'number' => $s['sender_phone'] ?? '' ] ],
            ],
            'from_location'    => [
                'code'    => (int) ( $s['sender_city_code'] ?? 44 ),
                'address' => $s['sender_address'] ?? '',
            ],
            'need_call'        => false,
        ];

        try {
            $api    = cdek_ship_get_api();
            $result = $api->create_intake( $payload );
            $uuid   = $result['entity']['uuid'] ?? '';
            if ( ! $uuid ) {
                throw new \RuntimeException( 'СДЭК не вернул uuid заявки.' );
            }
            $number = '';
            for ( $i = 0; $i < 3 && ! $number; $i++ ) {
                sleep( 1 );
                $info   = $api->get_intake( $uuid );
                $number = $info['entity']['intake_number'] ?? '';
                $errs   = [];
                foreach ( ( $info['requests'] ?? [] ) as $rq ) {
                    foreach ( ( $rq['errors'] ?? [] ) as $e ) {
                        $errs[] = $e['message'] ?? $e['code'] ?? '';
                    }
                }
                if ( $errs ) {
                    throw new \RuntimeException( implode( '; ', $errs ) );
                }
            }
            $number = $number ?: $uuid;

            // Отметить выбранные заказы как переданные курьеру
            foreach ( $order_ids as $oid ) {
                $o = wc_get_order( $oid );
                if ( $o ) {
                    $o->update_meta_data( self::META_DONE, $number );
                    $o->add_order_note( sprintf( 'СДЭК: включён в заявку на курьера № %s (%s, %s)', $number, $date, $time ) );
                    $o->save();
                }
            }

            update_option( self::OPT_LAST, [ 'uuid' => $uuid, 'number' => $number, 'date' => $date, 'orders' => $order_ids ] );
            $this->redirect_back( sprintf( 'Заявка на курьера № %s на %s (%s). Заказов в заборе: %d.', $number, $date, $time, count( $order_ids ) ), '' );
        } catch ( \Throwable $e ) {
            $this->redirect_back( '', 'Ошибка заявки: ' . $e->getMessage() );
        }
    }

    public function handle_cancel(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'cdek_cancel_intake' ) ) {
            wp_die( 'Доступ запрещён' );
        }
        $uuid = sanitize_text_field( wp_unslash( $_POST['uuid'] ?? '' ) );
        $last = get_option( self::OPT_LAST, [] );
        try {
            if ( $uuid ) {
                cdek_ship_get_api()->delete_intake( $uuid );
            }
            // снять отметку «передан курьеру» с заказов этой заявки
            foreach ( (array) ( $last['orders'] ?? [] ) as $oid ) {
                $o = wc_get_order( (int) $oid );
                if ( $o ) {
                    $o->delete_meta_data( self::META_DONE );
                    $o->save();
                }
            }
            delete_option( self::OPT_LAST );
            $this->redirect_back( 'Заявка на курьера отменена, заказы возвращены в список.', '' );
        } catch ( \Throwable $e ) {
            $this->redirect_back( '', 'Не удалось отменить: ' . $e->getMessage() );
        }
    }

    private function redirect_back( string $msg, string $err ): void {
        $url = add_query_arg(
            array_filter( [
                'page'     => CDEK_Hub::SLUG,
                'tab'      => 'courier',
                'cdek_msg' => $msg ?: null,
                'cdek_err' => $err ?: null,
            ] ),
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $url );
        exit;
    }
}
