<?php
/**
 * CDEK API v2.0 Client
 *
 * Handles authentication, delivery calculation, PVZ lookup, city search,
 * and order creation via CDEK REST API.
 *
 * @see https://api-docs.cdek.ru/29923741.html
 */
class CDEK_Shipping_API {

    /** @var string Production API base */
    const API_PROD = 'https://api.cdek.ru/v2';

    /** @var string Sandbox API base */
    const API_TEST = 'https://api.edu.cdek.ru/v2';

    /** @var string Test account */
    const TEST_ACCOUNT = 'wqGwiQx0gg8mLtiEKsUinjVSICCjtTEP';

    /** @var string Test secret */
    const TEST_SECRET = 'RmAmgvSgSl1yirlz9QupbzOJVqhCxcP5';

    /** @var string[] Tariff codes: склад → ПВЗ */
    const TARIFFS_WAREHOUSE_PVZ = [
        136 => 'Посылка (склад → ПВЗ)',
        234 => 'Экономичная посылка (склад → ПВЗ)',
    ];

    /** @var string[] Tariff codes: склад → постамат */
    const TARIFFS_WAREHOUSE_POSTAMAT = [
        368 => 'Посылка (склад → постамат)',
        378 => 'Экономичная посылка (склад → постамат)',
    ];

    /** @var string[] Tariff codes: склад → дверь (курьер) */
    const TARIFFS_WAREHOUSE_DOOR = [
        137 => 'Посылка (склад → дверь)',
        233 => 'Экономичная посылка (склад → дверь)',
    ];

    private string $account;
    private string $secret;
    private string $base_url;
    private ?string $token = null;
    private int $token_expires = 0;

    /**
     * @param string $account  Client ID
     * @param string $secret   Client secret
     * @param bool   $test_mode  Use sandbox API
     */
    public function __construct( string $account = '', string $secret = '', bool $test_mode = true ) {
        $this->account  = $account ?: self::TEST_ACCOUNT;
        $this->secret   = $secret ?: self::TEST_SECRET;
        $this->base_url = $test_mode ? self::API_TEST : self::API_PROD;
    }

    /* ─────────────────────────────────────────────────────────
     *  AUTH
     * ───────────────────────────────────────────────────────── */

    /**
     * Get OAuth2 bearer token (cached until expiry).
     */
    public function get_token(): string {
        if ( $this->token && time() < $this->token_expires ) {
            return $this->token;
        }

        // Check transient cache
        $cached = get_transient( 'cdek_ship_token' );
        if ( $cached ) {
            $this->token = $cached;
            $this->token_expires = time() + 1800; // assume half-life
            return $this->token;
        }

        $response = wp_remote_post( $this->base_url . '/oauth/token', [
            'body' => [
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->account,
                'client_secret' => $this->secret,
            ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( 'CDEK auth failed: ' . $response->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['access_token'] ) ) {
            $err = $body['error_description'] ?? $body['error'] ?? 'Unknown error';
            throw new \RuntimeException( 'CDEK auth error: ' . $err );
        }

        $this->token = $body['access_token'];
        $this->token_expires = time() + ( (int) ( $body['expires_in'] ?? 3600 ) - 60 );

        set_transient( 'cdek_ship_token', $this->token, (int) ( $body['expires_in'] ?? 3600 ) - 120 );

        return $this->token;
    }

    /* ─────────────────────────────────────────────────────────
     *  HTTP helpers
     * ───────────────────────────────────────────────────────── */

    private function request( string $method, string $endpoint, array $data = [] ): array {
        $url  = $this->base_url . $endpoint;
        $args = [
            'method'  => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->get_token(),
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 20,
        ];

        if ( $method === 'GET' && $data ) {
            $url = add_query_arg( $data, $url );
        } elseif ( $data ) {
            $args['body'] = wp_json_encode( $data );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( 'CDEK request failed: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 400 ) {
            $err_msg = '';
            if ( ! empty( $body['errors'] ) ) {
                foreach ( $body['errors'] as $e ) {
                    $err_msg .= ( $e['message'] ?? $e['code'] ?? '' ) . '; ';
                }
            }
            throw new \RuntimeException( "CDEK API {$code}: " . ( $err_msg ?: wp_remote_retrieve_body( $response ) ) );
        }

        return $body ?? [];
    }

    private function get( string $endpoint, array $params = [] ): array {
        return $this->request( 'GET', $endpoint, $params );
    }

    private function post( string $endpoint, array $data = [] ): array {
        return $this->request( 'POST', $endpoint, $data );
    }

    /* ─────────────────────────────────────────────────────────
     *  CALCULATOR
     * ───────────────────────────────────────────────────────── */

    /**
     * Calculate delivery cost for a specific tariff.
     *
     * @param int   $tariff_code    e.g. 136
     * @param array $from_location  ['code' => 44] or ['postal_code' => '107023']
     * @param array $to_location    ['code' => 270] or ['postal_code' => '630001']
     * @param array $packages       [['weight' => 1000, 'length' => 30, 'width' => 20, 'height' => 10]]
     * @return array {delivery_sum, period_min, period_max, currency, weight_calc, ...}
     */
    public function calculate_tariff( int $tariff_code, array $from_location, array $to_location, array $packages ): array {
        return $this->post( '/calculator/tariff', [
            'tariff_code'   => $tariff_code,
            'from_location' => $from_location,
            'to_location'   => $to_location,
            'packages'      => $packages,
        ] );
    }

    /**
     * Calculate delivery cost for ALL available tariffs at once.
     *
     * @return array {tariff_codes: [{tariff_code, tariff_name, tariff_description, delivery_sum, period_min, period_max, ...}]}
     */
    public function calculate_tarifflist( array $from_location, array $to_location, array $packages ): array {
        return $this->post( '/calculator/tarifflist', [
            'from_location' => $from_location,
            'to_location'   => $to_location,
            'packages'      => $packages,
        ] );
    }

    /**
     * Get cheapest tariff among warehouse→PVZ options.
     *
     * @return array|null {tariff_code, delivery_sum, period_min, period_max} or null if none available
     */
    public function get_cheapest_pvz_tariff( array $from_location, array $to_location, array $packages ): ?array {
        $best = null;

        foreach ( array_keys( self::TARIFFS_WAREHOUSE_PVZ ) as $code ) {
            try {
                $result = $this->calculate_tariff( $code, $from_location, $to_location, $packages );
                if ( ! empty( $result['delivery_sum'] ) ) {
                    if ( ! $best || $result['delivery_sum'] < $best['delivery_sum'] ) {
                        $best = [
                            'tariff_code' => $code,
                            'tariff_name' => self::TARIFFS_WAREHOUSE_PVZ[ $code ],
                            'delivery_sum' => (float) $result['delivery_sum'],
                            'period_min'   => (int) ( $result['period_min'] ?? 0 ),
                            'period_max'   => (int) ( $result['period_max'] ?? 0 ),
                            'currency'     => $result['currency'] ?? 'RUB',
                        ];
                    }
                }
            } catch ( \RuntimeException $e ) {
                // tariff not available for this route — skip
                continue;
            }
        }

        return $best;
    }

    /* ─────────────────────────────────────────────────────────
     *  PVZ / DELIVERY POINTS
     * ───────────────────────────────────────────────────────── */

    /**
     * Get pickup points (PVZ) by city code.
     *
     * @param int    $city_code  CDEK city code
     * @param string $type       PVZ | POSTAMAT | ALL
     * @return array[] List of delivery points
     */
    public function get_delivery_points( int $city_code, string $type = 'PVZ' ): array {
        $params = [
            'city_code' => $city_code,
            'type'      => $type,
        ];
        return $this->get( '/deliverypoints', $params );
    }

    /**
     * Get delivery points with caching (1 hour per city).
     */
    public function get_delivery_points_cached( int $city_code, string $type = 'PVZ' ): array {
        $cache_key = "cdek_ship_pvz_{$city_code}_{$type}";
        $cached = get_transient( $cache_key );

        if ( $cached !== false ) {
            return $cached;
        }

        $points = $this->get_delivery_points( $city_code, $type );

        // Cache for 1 hour
        set_transient( $cache_key, $points, HOUR_IN_SECONDS );

        return $points;
    }

    /* ─────────────────────────────────────────────────────────
     *  CITIES / LOCATIONS
     * ───────────────────────────────────────────────────────── */

    /**
     * Search cities by name or postal code.
     *
     * @param array $params  ['city' => 'Москва'] or ['postal_code' => '107023'] or ['code' => 44]
     * @return array[]
     */
    public function get_cities( array $params = [] ): array {
        $defaults = [ 'country_codes' => 'RU', 'size' => 20 ];
        return $this->get( '/location/cities', array_merge( $defaults, $params ) );
    }

    /**
     * Find city code by name (first match).
     */
    public function find_city_code( string $city_name ): ?int {
        $cities = $this->get_cities( [ 'city' => $city_name ] );
        return ! empty( $cities[0]['code'] ) ? (int) $cities[0]['code'] : null;
    }

    /**
     * Find city code by postal code.
     */
    public function find_city_by_postal( string $postal_code ): ?array {
        $cities = $this->get_cities( [ 'postal_code' => $postal_code ] );
        return $cities[0] ?? null;
    }

    /* ─────────────────────────────────────────────────────────
     *  ORDERS
     * ───────────────────────────────────────────────────────── */

    /**
     * Create a delivery order.
     *
     * @param array $order_data Full order payload per CDEK API spec
     * @return array {entity: {uuid}, requests: [...]}
     */
    public function create_order( array $order_data ): array {
        return $this->post( '/orders', $order_data );
    }

    /**
     * Get order info by UUID.
     */
    public function get_order( string $uuid ): array {
        return $this->get( '/orders/' . $uuid );
    }

    /**
     * Get order info by shop order number.
     */
    public function get_order_by_number( string $im_number ): array {
        return $this->get( '/orders', [ 'im_number' => $im_number ] );
    }

    /**
     * Build order payload for WooCommerce order.
     *
     * @param \WC_Order $wc_order
     * @param int       $tariff_code
     * @param string    $delivery_point_code  CDEK PVZ code
     * @param array     $from_location
     * @param array     $packages
     */
    public function build_order_payload(
        \WC_Order $wc_order,
        int $tariff_code,
        string $delivery_point_code,
        array $from_location,
        array $packages
    ): array {
        return [
            'type'            => 1, // 1 = интернет-магазин
            'number'          => (string) $wc_order->get_id(),
            'tariff_code'     => $tariff_code,
            'comment'         => 'Заказ #' . $wc_order->get_order_number(),
            'shipment_point'  => $from_location['code'] ?? null, // код ПВЗ отправки (если сдаёте в ПВЗ)
            'delivery_point'  => $delivery_point_code,
            'sender'          => [
                'name'   => get_bloginfo( 'name' ),
                'phones' => [],
            ],
            'recipient'       => [
                'name'   => $wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name(),
                'phones' => [ [ 'number' => $wc_order->get_billing_phone() ] ],
                'email'  => $wc_order->get_billing_email(),
            ],
            'from_location'   => $from_location,
            'packages'        => $packages,
        ];
    }

    /* ─────────────────────────────────────────────────────────
     *  PRINT / LABELS
     * ───────────────────────────────────────────────────────── */

    /**
     * POST request (alias for external use in admin class).
     */
    public function post_raw( string $endpoint, array $data = [] ): array {
        return $this->post( $endpoint, $data );
    }

    /**
     * Get print result as PDF binary.
     *
     * @param string $uuid Print request UUID
     * @return string|null PDF binary or null if not ready
     */
    public function get_print_pdf( string $uuid ): ?string {
        $url  = $this->base_url . '/print/orders/' . $uuid;
        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->get_token(),
                'Accept'        => 'application/pdf',
            ],
            'timeout' => 30,
        ];

        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );

        // 202 = not ready yet
        if ( $code === 202 ) {
            return null;
        }

        if ( $code !== 200 ) {
            return null;
        }

        $content_type = wp_remote_retrieve_header( $response, 'content-type' );
        if ( str_contains( $content_type, 'application/pdf' ) ) {
            return wp_remote_retrieve_body( $response );
        }

        // JSON response = check status
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $status = $body['entity']['statuses'][0]['code'] ?? '';
        if ( $status === 'READY' ) {
            // URL to download
            $url_pdf = $body['entity']['url'] ?? '';
            if ( $url_pdf ) {
                $pdf_response = wp_remote_get( $url_pdf, [ 'timeout' => 30 ] );
                if ( ! is_wp_error( $pdf_response ) && wp_remote_retrieve_response_code( $pdf_response ) === 200 ) {
                    return wp_remote_retrieve_body( $pdf_response );
                }
            }
        }

        return null;
    }

    /**
     * Create barcode (waybill) print request.
     *
     * @param string $order_uuid
     * @return string Print request UUID
     */
    public function create_barcode_print( string $order_uuid ): string {
        $result = $this->post( '/print/barcodes', [
            'orders' => [ [ 'order_uuid' => $order_uuid ] ],
        ] );
        return $result['entity']['uuid'] ?? '';
    }

    /**
     * Get barcode PDF.
     */
    public function get_barcode_pdf( string $print_uuid ): ?string {
        return $this->get_print_pdf( str_replace( '/print/orders/', '/print/barcodes/', '' ) );
    }

    /* ─────────────────────────────────────────────────────────
     *  WEBHOOKS
     * ───────────────────────────────────────────────────────── */

    /**
     * Register a webhook for order status updates.
     */
    public function register_webhook( string $url, string $type = 'ORDER_STATUS' ): array {
        return $this->post( '/webhooks', [
            'url'  => $url,
            'type' => $type,
        ] );
    }
}
