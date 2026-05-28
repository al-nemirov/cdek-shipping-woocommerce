<?php
/**
 * CDEK Admin: Order Metabox, Order Creation, Labels, Status Tracking
 *
 * Shows CDEK info in WooCommerce order page with:
 * - PVZ info (code + address)
 * - "Send to CDEK" button → creates delivery order via API
 * - Tracking number + status
 * - "Refresh status" button
 * - "Download label" button (PDF waybill)
 * - Full status history
 */
class CDEK_Shipping_Admin {

    public function __construct() {
        // Metabox
        add_action( 'add_meta_boxes', [ $this, 'add_metabox' ] );

        // Handle metabox actions (legacy posts + HPOS)
        add_action( 'woocommerce_process_shop_order_meta', [ $this, 'handle_actions' ], 50 );

        // Admin styles
        add_action( 'admin_head', [ $this, 'admin_css' ] );

        // AJAX: Download label
        add_action( 'wp_ajax_cdek_ship_download_label', [ $this, 'ajax_download_label' ] );
    }

    /**
     * Register metabox on order page.
     */
    public function add_metabox(): void {
        $screen = 'shop_order';
        try {
            if ( function_exists( 'wc_get_container' ) &&
                 class_exists( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class ) ) {
                $controller = wc_get_container()->get(
                    \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class
                );
                if ( $controller->custom_orders_table_usage_is_enabled() ) {
                    $screen = wc_get_page_screen_id( 'shop-order' );
                }
            }
        } catch ( \Throwable $e ) {
            // WC < 7.1 or HPOS classes not available — fallback to legacy
        }

        add_meta_box(
            'cdek_ship_metabox',
            '📦 СДЭК Доставка',
            [ $this, 'render_metabox' ],
            $screen,
            'side',
            'high'
        );
    }

    /**
     * Render metabox content.
     */
    public function render_metabox( $post_or_order ): void {
        $order = ( $post_or_order instanceof \WC_Order )
            ? $post_or_order
            : wc_get_order( $post_or_order->ID );

        if ( ! $order ) return;

        $pvz_code    = $order->get_meta( '_cdek_pvz_code' );
        $pvz_address = $order->get_meta( '_cdek_pvz_address' );
        $order_uuid  = $order->get_meta( '_cdek_order_uuid' );
        $cdek_number = $order->get_meta( '_cdek_tracking_number' );
        $status_code = $order->get_meta( '_cdek_last_status_code' );
        $status_name = $order->get_meta( '_cdek_last_status' );
        $status_date = $order->get_meta( '_cdek_last_status_date' );

        // Check if this is a CDEK order
        $is_cdek = false;
        foreach ( $order->get_shipping_methods() as $method ) {
            if ( str_contains( $method->get_method_id(), 'cdek_pvz' ) ) {
                $is_cdek = true;
                break;
            }
        }

        if ( ! $is_cdek && ! $pvz_code ) {
            echo '<p style="color:#999">Этот заказ не использует СДЭК.</p>';
            return;
        }

        wp_nonce_field( 'cdek_ship_metabox', 'cdek_ship_metabox_nonce' );

        // ── PVZ info ──
        if ( $pvz_code ) {
            echo '<div class="cdek-meta-section">';
            echo '<strong>ПВЗ:</strong> ' . esc_html( $pvz_code ) . '<br>';
            if ( $pvz_address ) {
                echo '<span style="color:#666;font-size:12px">' . esc_html( $pvz_address ) . '</span>';
            }
            echo '</div>';
        }

        // ── Order UUID / tracking ──
        if ( $order_uuid ) {
            echo '<div class="cdek-meta-section">';

            if ( $cdek_number ) {
                echo '<strong>Трек-номер:</strong> ';
                echo '<a href="https://www.cdek.ru/ru/tracking?order_id=' . esc_attr( $cdek_number ) . '" target="_blank" rel="noopener">';
                echo esc_html( $cdek_number ) . ' ↗</a><br>';
            }

            echo '<span style="font-size:11px;color:#999">UUID: ' . esc_html( $order_uuid ) . '</span>';
            echo '</div>';
        }

        // ── Status ──
        if ( $status_code ) {
            $color = $this->get_status_color( $status_code );
            echo '<div class="cdek-meta-section">';
            echo '<strong>Статус:</strong> ';
            echo '<span style="color:' . esc_attr( $color ) . ';font-weight:600">' . esc_html( $status_name ?: $status_code ) . '</span>';
            if ( $status_date ) {
                $formatted = '';
                try {
                    $dt = new \DateTime( $status_date );
                    $formatted = $dt->format( 'd.m.Y H:i' );
                } catch ( \Throwable $e ) {
                    $formatted = $status_date;
                }
                echo '<br><span style="color:#999;font-size:11px">' . esc_html( $formatted ) . '</span>';
            }
            echo '</div>';
        }

        // ── Buttons ──
        echo '<div class="cdek-meta-buttons">';

        if ( ! $order_uuid ) {
            // Not yet sent to CDEK
            echo '<button type="submit" name="cdek_ship_create_order" value="1" class="button button-primary" style="width:100%;margin-bottom:6px">';
            echo '📤 Отправить в СДЭК</button>';
        } else {
            // Already sent — show management buttons
            echo '<button type="submit" name="cdek_ship_refresh_status" value="1" class="button" style="width:100%;margin-bottom:6px">';
            echo '🔄 Обновить статус</button>';

            echo '<button type="button" class="button cdek-download-label" data-order="' . esc_attr( $order->get_id() ) . '" style="width:100%;margin-bottom:6px">';
            echo '🏷️ Скачать этикетку</button>';

            // Resend button
            echo '<hr style="margin:8px 0">';
            echo '<button type="submit" name="cdek_ship_resend" value="1" class="button" ';
            echo 'onclick="return confirm(\'Пересоздать заявку? Старая будет удалена из меты.\')" ';
            echo 'style="width:100%;color:#999;border-color:#ccc;font-size:11px">';
            echo '♻️ Пересоздать заявку</button>';
        }

        echo '</div>';

        // ── Status history from order notes ──
        $notes = wc_get_order_notes( [
            'order_id' => $order->get_id(),
            'type'     => 'internal',
        ] );
        $cdek_notes = array_filter( $notes, fn( $n ) => str_starts_with( $n->content, 'СДЭК:' ) );

        if ( $cdek_notes ) {
            echo '<div class="cdek-meta-section" style="margin-top:8px">';
            echo '<details><summary style="cursor:pointer;font-size:12px;color:#666">История статусов (' . count( $cdek_notes ) . ')</summary>';
            echo '<div style="max-height:200px;overflow-y:auto;margin-top:4px">';
            foreach ( array_slice( $cdek_notes, 0, 15 ) as $note ) {
                echo '<div style="font-size:11px;color:#666;padding:2px 0;border-bottom:1px solid #f0f0f0">';
                echo '<span style="color:#999">' . esc_html( date_i18n( 'd.m H:i', strtotime( $note->date_created ) ) ) . '</span> ';
                echo esc_html( $note->content );
                echo '</div>';
            }
            echo '</div></details></div>';
        }

        // Label download JS
        ?>
        <script>
        jQuery(function($) {
            $('.cdek-download-label').on('click', function() {
                var orderId = $(this).data('order');
                var $btn = $(this);
                $btn.prop('disabled', true).text('Загрузка...');
                window.location.href = ajaxurl + '?action=cdek_ship_download_label&order_id=' + orderId +
                    '&_wpnonce=<?= wp_create_nonce( 'cdek_ship_label' ) ?>';
                setTimeout(function() { $btn.prop('disabled', false).html('🏷️ Скачать этикетку'); }, 3000);
            });
        });
        </script>
        <?php
    }

    /**
     * Handle metabox button actions.
     */
    public function handle_actions( $order_id ): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        if ( ! isset( $_POST['cdek_ship_metabox_nonce'] ) ||
             ! wp_verify_nonce( $_POST['cdek_ship_metabox_nonce'], 'cdek_ship_metabox' ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // ── Create order in CDEK ──
        if ( isset( $_POST['cdek_ship_create_order'] ) ) {
            $this->create_cdek_order( $order );
        }

        // ── Refresh status ──
        if ( isset( $_POST['cdek_ship_refresh_status'] ) ) {
            $this->refresh_status( $order );
        }

        // ── Resend (clear + recreate) ──
        if ( isset( $_POST['cdek_ship_resend'] ) ) {
            $order->delete_meta_data( '_cdek_order_uuid' );
            $order->delete_meta_data( '_cdek_tracking_number' );
            $order->delete_meta_data( '_cdek_last_status' );
            $order->delete_meta_data( '_cdek_last_status_code' );
            $order->delete_meta_data( '_cdek_last_status_date' );
            $order->add_order_note( 'СДЭК: данные заявки сброшены, пересоздание...' );
            $order->save();
            $this->create_cdek_order( $order );
        }
    }

    /**
     * Create delivery order in CDEK via API.
     */
    private function create_cdek_order( \WC_Order $order ): void {
        $pvz_code = $order->get_meta( '_cdek_pvz_code' );
        if ( ! $pvz_code ) {
            $order->add_order_note( 'СДЭК: ошибка — не выбран ПВЗ.' );
            $order->save();
            return;
        }

        try {
            $api = cdek_ship_get_api();

            // Get shipping method settings
            $settings = $this->get_shipping_settings( $order );
            $sender_code = (int) ( $settings['sender_city_code'] ?? 44 );
            $sender_postal = $settings['sender_postal_code'] ?? '107023';

            $from_location = [ 'code' => $sender_code ];
            if ( $sender_postal ) {
                $from_location['postal_code'] = $sender_postal;
            }

            // Build packages from order
            $packages = $this->build_packages_from_order( $order, $settings );

            // Determine tariff
            $tariff_codes = $settings['tariffs'] ?? [ '136', '234' ];
            $tariff_code  = (int) $tariff_codes[0]; // Use first configured tariff

            // Build payload
            $payload = $api->build_order_payload(
                $order,
                $tariff_code,
                $pvz_code,
                $from_location,
                $packages
            );

            $result = $api->create_order( $payload );

            // Extract UUID
            $uuid = $result['entity']['uuid'] ?? '';
            if ( ! $uuid ) {
                $errors = '';
                foreach ( $result['requests'] ?? [] as $req ) {
                    foreach ( $req['errors'] ?? [] as $err ) {
                        $errors .= ( $err['message'] ?? $err['code'] ?? '' ) . '; ';
                    }
                }
                throw new \RuntimeException( $errors ?: 'Нет UUID в ответе' );
            }

            $order->update_meta_data( '_cdek_order_uuid', $uuid );
            $order->update_meta_data( '_cdek_last_status_code', 'CREATED' );
            $order->update_meta_data( '_cdek_last_status', 'Создан' );
            $order->update_meta_data( '_cdek_last_status_date', current_time( 'Y-m-d\TH:i:s' ) );
            $order->add_order_note( 'СДЭК: заказ создан. UUID: ' . $uuid );
            $order->save();

            // Try to get tracking number (may not be available immediately)
            try {
                $info = $api->get_order( $uuid );
                $cdek_number = $info['entity']['cdek_number'] ?? '';
                if ( $cdek_number ) {
                    $order->update_meta_data( '_cdek_tracking_number', $cdek_number );
                    $order->add_order_note( 'СДЭК: трек-номер ' . $cdek_number );
                    $order->save();
                }
            } catch ( \Throwable $e ) {
                // Tracking will be fetched by cron later
            }

        } catch ( \Throwable $e ) {
            $order->add_order_note( 'СДЭК: ошибка создания заказа — ' . $e->getMessage() );
            $order->save();
        }
    }

    /**
     * Refresh order status from CDEK API.
     */
    private function refresh_status( \WC_Order $order ): void {
        $uuid = $order->get_meta( '_cdek_order_uuid' );
        if ( ! $uuid ) {
            $order->add_order_note( 'СДЭК: нет UUID для обновления статуса.' );
            $order->save();
            return;
        }

        try {
            $api  = cdek_ship_get_api();
            $info = $api->get_order( $uuid );

            $statuses = $info['entity']['statuses'] ?? [];
            if ( ! empty( $statuses ) ) {
                $latest = $statuses[0];
                $order->update_meta_data( '_cdek_last_status_code', $latest['code'] ?? '' );
                $order->update_meta_data( '_cdek_last_status', $latest['name'] ?? '' );
                $order->update_meta_data( '_cdek_last_status_date', $latest['date_time'] ?? '' );
                $order->add_order_note( sprintf( 'СДЭК: %s (%s)',
                    $latest['name'] ?? '?',
                    $latest['code'] ?? '?'
                ) );
            }

            // Tracking number
            $cdek_number = $info['entity']['cdek_number'] ?? '';
            if ( $cdek_number ) {
                $order->update_meta_data( '_cdek_tracking_number', $cdek_number );
            }

            $order->save();

        } catch ( \Throwable $e ) {
            $order->add_order_note( 'СДЭК: ошибка обновления статуса — ' . $e->getMessage() );
            $order->save();
        }
    }

    /**
     * AJAX: Download waybill label as PDF.
     */
    public function ajax_download_label(): void {
        check_ajax_referer( 'cdek_ship_label' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Нет доступа' );
        }

        $order_id = (int) ( $_GET['order_id'] ?? 0 );
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_die( 'Заказ не найден' );
        }

        $uuid = $order->get_meta( '_cdek_order_uuid' );
        if ( ! $uuid ) {
            wp_die( 'Заказ не отправлен в СДЭК' );
        }

        try {
            $api = cdek_ship_get_api();

            // Request barcode/waybill
            $result = $api->post_raw( '/print/orders', [
                'orders' => [ [ 'order_uuid' => $uuid ] ],
            ] );

            $print_uuid = $result['entity']['uuid'] ?? '';
            if ( ! $print_uuid ) {
                wp_die( 'Не удалось создать запрос на печать' );
            }

            // Wait briefly and try to get PDF (max 2 attempts, non-blocking friendly)
            sleep( 3 );
            $pdf = $api->get_print_pdf( $print_uuid );

            if ( ! $pdf ) {
                // Second attempt after short wait
                sleep( 3 );
                $pdf = $api->get_print_pdf( $print_uuid );
            }

            if ( ! $pdf ) {
                wp_die( 'Этикетка ещё не готова. Попробуйте через 30 секунд.' );
            }

            header( 'Content-Type: application/pdf' );
            header( 'Content-Disposition: attachment; filename="cdek-label-' . $order_id . '.pdf"' );
            header( 'Content-Length: ' . strlen( $pdf ) );
            echo $pdf;
            exit;

        } catch ( \Throwable $e ) {
            wp_die( 'Ошибка: ' . esc_html( $e->getMessage() ) );
        }
    }

    /**
     * Get shipping method settings for order.
     */
    private function get_shipping_settings( \WC_Order $order ): array {
        foreach ( $order->get_shipping_methods() as $method ) {
            if ( str_contains( $method->get_method_id(), 'cdek_pvz' ) ) {
                $instance_id = $method->get_instance_id();
                $shipping = new \CDEK_Shipping_Method( $instance_id );
                return $shipping->instance_settings ?? [];
            }
        }

        // Fallback: find any cdek_pvz method
        $zones = \WC_Shipping_Zones::get_zones();
        foreach ( $zones as $zone ) {
            foreach ( $zone['shipping_methods'] as $m ) {
                if ( $m->id === 'cdek_pvz' ) {
                    return $m->instance_settings ?? [];
                }
            }
        }

        return [];
    }

    /**
     * Build CDEK packages from WC order items.
     */
    private function build_packages_from_order( \WC_Order $order, array $settings ): array {
        $default_weight = (int) ( $settings['default_weight'] ?? 500 );
        $default_length = (int) ( $settings['default_length'] ?? 25 );
        $default_width  = (int) ( $settings['default_width'] ?? 20 );
        $default_height = (int) ( $settings['default_height'] ?? 5 );

        $total_weight = 0;
        $max_length   = 0;
        $max_width    = 0;
        $total_height = 0;
        $items        = [];

        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            $qty     = $item->get_quantity();

            if ( ! $product ) continue;

            // Weight in grams
            $w = (float) $product->get_weight();
            if ( $w > 0 ) {
                $unit = get_option( 'woocommerce_weight_unit', 'kg' );
                $w = wc_get_weight( $w, 'g', $unit );
            } else {
                $w = $default_weight;
            }
            $total_weight += $w * $qty;

            // Dimensions in cm (convert from WC units)
            $dim_unit = get_option( 'woocommerce_dimension_unit', 'cm' );
            $l  = (float) $product->get_length();
            $wi = (float) $product->get_width();
            $h  = (float) $product->get_height();
            if ( $l > 0 ) { $l = wc_get_dimension( $l, 'cm', $dim_unit ); } else { $l = $default_length; }
            if ( $wi > 0 ) { $wi = wc_get_dimension( $wi, 'cm', $dim_unit ); } else { $wi = $default_width; }
            if ( $h > 0 ) { $h = wc_get_dimension( $h, 'cm', $dim_unit ); } else { $h = $default_height; }

            $max_length   = max( $max_length, $l );
            $max_width    = max( $max_width, $wi );
            $total_height += $h * $qty;

            // CDEK items list
            $items[] = [
                'ware_key' => (string) ( $product->get_sku() ?: $product->get_id() ),
                'payment'  => [ 'value' => 0 ], // prepaid
                'name'     => mb_substr( $item->get_name(), 0, 255 ),
                'cost'     => (float) ( $item->get_total() / max( $qty, 1 ) ),
                'amount'   => $qty,
                'weight'   => (int) round( $w ),
            ];
        }

        if ( $total_weight < 100 )  $total_weight = $default_weight;
        if ( $max_length < 1 )      $max_length = $default_length;
        if ( $max_width < 1 )       $max_width = $default_width;
        if ( $total_height < 1 )    $total_height = $default_height;

        return [ [
            'number'  => 'PKG-' . $order->get_id(),
            'weight'  => (int) round( $total_weight ),
            'length'  => (int) ceil( $max_length ),
            'width'   => (int) ceil( $max_width ),
            'height'  => (int) ceil( $total_height ),
            'items'   => $items,
        ] ];
    }

    /**
     * Admin CSS for metabox.
     */
    public function admin_css(): void {
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->id, [ 'shop_order', wc_get_page_screen_id( 'shop-order' ) ] ) ) {
            return;
        }
        echo '<style>
        .cdek-meta-section { padding:6px 0; border-bottom:1px solid #f0f0f0; font-size:13px; }
        .cdek-meta-buttons { padding:8px 0 0; }
        #cdek_ship_metabox .inside { padding:6px 12px; }
        </style>';
    }

    /**
     * Get color for CDEK status code.
     */
    private function get_status_color( string $code ): string {
        return match ( true ) {
            str_contains( $code, 'DELIVERED' )         => '#4CAF50',
            str_contains( $code, 'READY' )             => '#4CAF50',
            str_contains( $code, 'RECEIVED' )          => '#FF9800',
            str_contains( $code, 'TRANSIT' )            => '#FF9800',
            str_contains( $code, 'CREATED' )           => '#2196F3',
            str_contains( $code, 'ACCEPTED' )          => '#2196F3',
            str_contains( $code, 'INVALID' ),
            str_contains( $code, 'NOT_DELIVERED' ),
            str_contains( $code, 'REMOVED' )           => '#f44336',
            default                                    => '#666',
        };
    }
}

// Initialize
new CDEK_Shipping_Admin();
