<?php
/**
 * WooCommerce Shipping Method: СДЭК ПВЗ
 *
 * Calculates shipping cost via CDEK API (tariffs 136/234: склад → ПВЗ).
 * Shows CDEK pickup points on checkout for customer selection.
 */
class CDEK_Shipping_Method extends WC_Shipping_Method {

    /** @var CDEK_Shipping_API */
    private $api;

    public function __construct( $instance_id = 0 ) {
        $this->id                 = 'cdek_pvz';
        $this->instance_id        = absint( $instance_id );
        $this->method_title       = 'СДЭК — ПВЗ';
        $this->method_description = 'Доставка СДЭК до пункта выдачи. Магазин отправляет посылку в СДЭК, клиент забирает из ПВЗ.';
        $this->supports           = [ 'shipping-zones', 'instance-settings', 'instance-settings-modal' ];
        $this->enabled            = 'yes';

        $this->init();
    }

    private function init(): void {
        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option( 'title', 'СДЭК — Пункт выдачи' );

        add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
    }

    public function init_form_fields(): void {
        $this->instance_form_fields = [
            'title' => [
                'title'   => 'Название',
                'type'    => 'text',
                'default' => 'СДЭК — Пункт выдачи',
            ],
            'sender_city_code' => [
                'title'       => 'Город отправки (код СДЭК)',
                'type'        => 'number',
                'default'     => '44',
                'description' => 'Код города СДЭК. Москва = 44, СПб = 137. <a href="https://api-docs.cdek.ru/36982648.html" target="_blank">Справочник</a>',
            ],
            'sender_postal_code' => [
                'title'       => 'Индекс отправки',
                'type'        => 'text',
                'default'     => '107023',
                'description' => 'Почтовый индекс склада/офиса отправителя.',
            ],
            'default_weight' => [
                'title'       => 'Вес по умолч. (г)',
                'type'        => 'number',
                'default'     => '500',
                'description' => 'Вес товара если не задан в карточке (граммы).',
            ],
            'default_length' => [
                'title'   => 'Длина по умолч. (см)',
                'type'    => 'number',
                'default' => '25',
            ],
            'default_width' => [
                'title'   => 'Ширина по умолч. (см)',
                'type'    => 'number',
                'default' => '20',
            ],
            'default_height' => [
                'title'   => 'Высота по умолч. (см)',
                'type'    => 'number',
                'default' => '5',
            ],
            'markup' => [
                'title'       => 'Наценка (₽)',
                'type'        => 'number',
                'default'     => '0',
                'description' => 'Фиксированная наценка к стоимости СДЭК (на упаковку, привоз на склад и т.д.).',
            ],
            'markup_percent' => [
                'title'       => 'Наценка (%)',
                'type'        => 'number',
                'default'     => '0',
                'description' => 'Процентная наценка к стоимости СДЭК.',
            ],
            'free_shipping_min' => [
                'title'       => 'Бесплатная доставка от (₽)',
                'type'        => 'number',
                'default'     => '0',
                'description' => 'Сумма заказа для бесплатной доставки. 0 = выключено.',
            ],
            'tariffs' => [
                'title'       => 'Тарифы',
                'type'        => 'multiselect',
                'default'     => [ '136', '234' ],
                'options'     => [
                    '136' => '136 — Посылка склад → ПВЗ (магазин сам везёт в СДЭК)',
                    '234' => '234 — Экономичная посылка склад → ПВЗ',
                    '368' => '368 — Посылка склад → постамат',
                    '378' => '378 — Экономичная посылка склад → постамат',
                    '138' => '138 — Посылка дверь → ПВЗ (курьер забирает у магазина)',
                    '139' => '139 — Посылка дверь → дверь (курьер забирает и везёт до двери)',
                ],
                'description' => 'Какие тарифы предлагать покупателю (показывается самый дешёвый из отмеченных, его же тариф уходит в заявку). «склад» = магазин сам привозит посылку в СДЭК. «дверь» = курьер СДЭК забирает у магазина — забор включён в тариф (дороже), отдельный вызов курьера не нужен.',
            ],
        ];
    }

    /**
     * Calculate shipping cost.
     * Called by WooCommerce when checkout recalculates shipping.
     *
     * @param array $package WC shipping package (items, destination, etc.)
     */
    public function calculate_shipping( $package = [] ): void {
        $destination = $package['destination'] ?? [];
        $city        = $destination['city'] ?? '';
        $postcode    = $destination['postcode'] ?? '';

        // Need at least a city or postcode to calculate
        if ( empty( $city ) && empty( $postcode ) ) {
            return;
        }

        try {
            $api = $this->get_api();

            // Build from_location
            $from = [ 'postal_code' => $this->get_option( 'sender_postal_code', '107023' ) ];
            $sender_code = (int) $this->get_option( 'sender_city_code', 44 );
            if ( $sender_code ) {
                $from['code'] = $sender_code;
            }

            // Build to_location
            $to = [];
            if ( $postcode ) {
                $to['postal_code'] = $postcode;
            }
            if ( $city ) {
                $to['city'] = $city;
            }

            // Build packages from cart
            $packages_data = $this->build_packages( $package );

            // Calculate for enabled tariffs
            $tariffs = $this->get_option( 'tariffs', [ '136', '234' ] );
            $best = null;

            foreach ( $tariffs as $tariff_code ) {
                try {
                    $result = $api->calculate_tariff( (int) $tariff_code, $from, $to, $packages_data );

                    if ( ! empty( $result['delivery_sum'] ) ) {
                        $sum = (float) $result['delivery_sum'];
                        if ( ! $best || $sum < $best['sum'] ) {
                            $best = [
                                'sum'        => $sum,
                                'period_min' => (int) ( $result['period_min'] ?? 0 ),
                                'period_max' => (int) ( $result['period_max'] ?? 0 ),
                                'tariff'     => (int) $tariff_code,
                            ];
                        }
                    }
                } catch ( \RuntimeException $e ) {
                    // Tariff not available for this route
                    continue;
                }
            }

            if ( ! $best ) {
                return; // No tariff available
            }

            // Apply markup
            $cost = $best['sum'];
            $markup_fixed   = (float) $this->get_option( 'markup', 0 );
            $markup_percent = (float) $this->get_option( 'markup_percent', 0 );
            $cost += $markup_fixed;
            if ( $markup_percent > 0 ) {
                $cost *= ( 1 + $markup_percent / 100 );
            }
            $cost = ceil( $cost ); // Round up

            // Free shipping threshold
            $free_min = (float) $this->get_option( 'free_shipping_min', 0 );
            $cart_total = WC()->cart ? WC()->cart->get_subtotal() : 0;
            if ( $free_min > 0 && $cart_total >= $free_min ) {
                $cost = 0;
            }

            // Build label
            $days = '';
            if ( $best['period_min'] && $best['period_max'] ) {
                $days = " ({$best['period_min']}–{$best['period_max']} дн.)";
            } elseif ( $best['period_max'] ) {
                $days = " (~{$best['period_max']} дн.)";
            }

            $label = $this->title . $days;

            $this->add_rate( [
                'id'        => $this->get_rate_id(),
                'label'     => $label,
                'cost'      => $cost,
                'meta_data' => [
                    'cdek_tariff'    => $best['tariff'],
                    'cdek_period'    => $best['period_min'] . '-' . $best['period_max'],
                    'cdek_base_cost' => $best['sum'],
                ],
            ] );

        } catch ( \RuntimeException $e ) {
            // API error — log and silently skip
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'CDEK Shipping error: ' . $e->getMessage() );
            }
        }
    }

    /**
     * Build CDEK packages array from WC cart package.
     */
    private function build_packages( array $package ): array {
        $total_weight = 0;
        $max_length   = 0;
        $max_width    = 0;
        $total_height = 0;

        $default_weight = (int) $this->get_option( 'default_weight', 500 );
        $default_length = (int) $this->get_option( 'default_length', 25 );
        $default_width  = (int) $this->get_option( 'default_width', 20 );
        $default_height = (int) $this->get_option( 'default_height', 5 );

        foreach ( $package['contents'] ?? [] as $item ) {
            $product = $item['data'] ?? null;
            $qty     = $item['quantity'] ?? 1;

            if ( ! $product instanceof WC_Product ) continue;

            // Weight in grams
            $w = (float) $product->get_weight();
            if ( $w > 0 ) {
                // WC weight is in kg by default (Russian stores may use grams — check wc_get_weight_units)
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

            $max_length = max( $max_length, $l );
            $max_width  = max( $max_width, $wi );
            $total_height += $h * $qty;
        }

        // At least some defaults
        if ( $total_weight < 100 ) $total_weight = $default_weight;
        if ( $max_length < 1 )     $max_length = $default_length;
        if ( $max_width < 1 )      $max_width = $default_width;
        if ( $total_height < 1 )   $total_height = $default_height;

        return [ [
            'weight' => (int) round( $total_weight ),
            'length' => (int) ceil( $max_length ),
            'width'  => (int) ceil( $max_width ),
            'height' => (int) ceil( $total_height ),
        ] ];
    }

    /**
     * Lazy-load CDEK API instance.
     */
    private function get_api(): CDEK_Shipping_API {
        if ( ! $this->api ) {
            $settings  = get_option( 'cdek_ship_settings', [] );
            $account   = $settings['account'] ?? '';
            $secret    = $settings['secret'] ?? '';
            $test_mode = ( $settings['test_mode'] ?? 'yes' ) === 'yes';

            $this->api = new CDEK_Shipping_API( $account, $secret, $test_mode );
        }
        return $this->api;
    }
}
