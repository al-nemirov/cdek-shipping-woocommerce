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

    /** @var string Публичные тестовые ключи СДЭК (sandbox, api.edu.cdek.ru) — из офиц. документации. */
    const TEST_ACCOUNT = 'EMscd6r9JnFiQ3bLoyjJY6eM78JrJceI';

    /** @var string Публичный тестовый secret СДЭК (sandbox). */
    const TEST_SECRET = 'PjLZkKBHEiLK3YsjtNrt3TGNG0ahs3kG';

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

        // Check transient cache (separate per environment to avoid test/prod conflicts)
        $cache_key = 'cdek_ship_token_' . ( $this->base_url === self::API_TEST ? 'test' : 'prod' );
        $cached = get_transient( $cache_key );
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

        set_transient( $cache_key, $this->token, (int) ( $body['expires_in'] ?? 3600 ) - 120 );

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
            'type'          => 2, // тип договора «доставка» (не интернет-магазин)
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
            'type'          => 2, // тип договора «доставка»
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
     * Find city code by name.
     *
     * ВАЖНО: у СДЭК встречаются города-омонимы (напр. «Краснодар» в Краснодарском
     * крае — 107 ПВЗ, и «Краснодар» в Кемеровской области — 0 ПВЗ). Порядок выдачи
     * /location/cities НЕ гарантирован, поэтому слепой cities[0] иногда давал город
     * без ПВЗ → «город не работает». Берём не первый, а тот, у кого реально есть ПВЗ.
     */
    public function find_city_code( string $city_name ): ?int {
        $ck = 'cdek_ship_citycode_' . md5( mb_strtolower( trim( $city_name ) ) );
        $cached = get_transient( $ck );
        if ( $cached !== false ) {
            return $cached ? (int) $cached : null;
        }

        $cities = $this->get_cities( [ 'city' => $city_name ] );
        if ( empty( $cities ) ) {
            set_transient( $ck, 0, DAY_IN_SECONDS );
            return null;
        }

        // Один кандидат — без лишних запросов.
        if ( count( $cities ) === 1 ) {
            $code = (int) ( $cities[0]['code'] ?? 0 );
            set_transient( $ck, $code, DAY_IN_SECONDS );
            return $code ?: null;
        }

        // Несколько одноимённых — выбираем город с максимальным числом ПВЗ.
        $best = 0;
        $best_count = -1;
        foreach ( $cities as $c ) {
            $code = (int) ( $c['code'] ?? 0 );
            if ( ! $code ) {
                continue;
            }
            $points = $this->get_delivery_points_cached( $code, 'PVZ' );
            $n = is_array( $points ) ? count( $points ) : 0;
            if ( $n > $best_count ) {
                $best_count = $n;
                $best = $code;
            }
        }
        // Если ни у кого нет ПВЗ — вернём первый, чтобы не падать.
        if ( ! $best ) {
            $best = (int) ( $cities[0]['code'] ?? 0 );
        }
        set_transient( $ck, $best, DAY_IN_SECONDS );
        return $best ?: null;
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
        // Отправитель из настроек (договор «доставка» требует company + phone)
        $settings       = get_option( 'cdek_ship_settings', [] );
        $sender_company = $settings['sender_company'] ?? get_bloginfo( 'name' );
        $sender_contact = $settings['sender_contact'] ?? $sender_company;
        $sender_phone   = $settings['sender_phone'] ?? '';

        $sender = [ 'company' => $sender_company, 'name' => $sender_contact ];
        if ( $sender_phone !== '' ) {
            $sender['phones'] = [ [ 'number' => $sender_phone ] ];
        }

        // Каждое грузоместо обязано иметь comment (требование type=2)
        foreach ( $packages as &$_pkg ) {
            if ( empty( $_pkg['comment'] ) ) {
                $_pkg['comment'] = $settings['package_comment'] ?? 'Книги';
            }
        }
        unset( $_pkg );

        $payload = [
            'type'            => 2, // 2 = доставка (договор НЕ интернет-магазин)
            'number'          => (string) $wc_order->get_id(),
            'tariff_code'     => $tariff_code,
            'comment'         => 'Заказ #' . $wc_order->get_order_number(),
            'delivery_point'  => $delivery_point_code,
            'sender'          => $sender,
            'recipient'       => [
                'name'   => $wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name(),
                'phones' => [ [ 'number' => $wc_order->get_billing_phone() ] ],
                'email'  => $wc_order->get_billing_email(),
            ],
            'from_location'   => $from_location,
            'packages'        => array_values( $packages ),
        ];

        // shipment_point — только если передан конкретный код ПВЗ/склада СДЭК (не город)
        // Если магазин вызывает курьера — этого поля не будет, используется from_location
        if ( ! empty( $from_location['pvz_code'] ) ) {
            $payload['shipment_point'] = $from_location['pvz_code'];
        }

        return $payload;
    }

    /* ─────────────────────────────────────────────────────────
     *  INTAKE — заявка на вызов курьера
     * ───────────────────────────────────────────────────────── */

    /**
     * Создать заявку на вызов курьера (один забор на день для всех заказов).
     *
     * @param array $data intake_date, intake_time_from/to, name, weight, comment, sender, from_location
     * @return array
     */
    public function create_intake( array $data ): array {
        return $this->post( '/intakes', $data );
    }

    /** Получить заявку на вызов курьера по uuid. */
    public function get_intake( string $uuid ): array {
        return $this->get( '/intakes/' . $uuid );
    }

    /** Отменить заявку на вызов курьера. */
    public function delete_intake( string $uuid ): array {
        return $this->request( 'DELETE', '/intakes/' . $uuid );
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
        // Запрашиваем СТАТУС (JSON), а не сам PDF: с Accept: application/pdf СДЭК отдаёт 202,
        // пока бинарник не материализован, хотя статус печати уже READY. Берём JSON → url → PDF.
        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->get_token(),
                'Accept'        => 'application/json',
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

        // JSON response = проверяем статусы. READY может быть НЕ первым в массиве
        // (ACCEPTED → PROCESSING → READY), поэтому ищем READY по всему списку.
        $body     = json_decode( wp_remote_retrieve_body( $response ), true );
        $statuses = $body['entity']['statuses'] ?? [];
        $is_ready = false;
        foreach ( (array) $statuses as $st ) {
            if ( ( $st['code'] ?? '' ) === 'READY' ) { $is_ready = true; break; }
        }
        if ( ! $is_ready ) {
            return null;
        }

        // Ссылка на PDF (или собираем сами). Скачиваем С АВТОРИЗАЦИЕЙ (это эндпоинт API).
        $url_pdf = $body['entity']['url'] ?? ( $this->base_url . '/print/orders/' . $uuid . '.pdf' );
        $pdf_response = wp_remote_get( $url_pdf, [
            'headers' => [ 'Authorization' => 'Bearer ' . $this->get_token() ],
            'timeout' => 30,
        ] );
        if ( ! is_wp_error( $pdf_response ) && wp_remote_retrieve_response_code( $pdf_response ) === 200 ) {
            $pdf_body = wp_remote_retrieve_body( $pdf_response );
            // санити: настоящий PDF начинается с %PDF
            if ( strncmp( (string) $pdf_body, '%PDF', 4 ) === 0 ) {
                return $pdf_body;
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
     *
     * @param string $print_uuid  Barcode print request UUID
     * @return string|null PDF binary or null if not ready
     */
    public function get_barcode_pdf( string $print_uuid ): ?string {
        $url = $this->base_url . '/print/barcodes/' . $print_uuid;
        // Статус JSON (с Accept: application/pdf СДЭК отдаёт 202, пока бинарник не готов).
        $response = wp_remote_get( $url, [
            'headers' => [ 'Authorization' => 'Bearer ' . $this->get_token(), 'Accept' => 'application/json' ],
            'timeout' => 30,
        ] );
        if ( is_wp_error( $response ) ) {
            return null;
        }
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code === 202 || $code !== 200 ) {
            return null;
        }
        $ct = wp_remote_retrieve_header( $response, 'content-type' );
        if ( str_contains( (string) $ct, 'application/pdf' ) ) {
            return wp_remote_retrieve_body( $response );
        }
        // JSON: ищем READY по всему списку (не только [0]), затем качаем .pdf с авторизацией
        $body     = json_decode( wp_remote_retrieve_body( $response ), true );
        $is_ready = false;
        foreach ( (array) ( $body['entity']['statuses'] ?? [] ) as $st ) {
            if ( ( $st['code'] ?? '' ) === 'READY' ) { $is_ready = true; break; }
        }
        if ( ! $is_ready ) {
            return null;
        }
        $url_pdf = $body['entity']['url'] ?? ( $this->base_url . '/print/barcodes/' . $print_uuid . '.pdf' );
        $pdf_response = wp_remote_get( $url_pdf, [
            'headers' => [ 'Authorization' => 'Bearer ' . $this->get_token() ],
            'timeout' => 30,
        ] );
        if ( ! is_wp_error( $pdf_response ) && wp_remote_retrieve_response_code( $pdf_response ) === 200 ) {
            $pdf_body = wp_remote_retrieve_body( $pdf_response );
            if ( strncmp( (string) $pdf_body, '%PDF', 4 ) === 0 ) {
                return $pdf_body;
            }
        }
        return null;
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
