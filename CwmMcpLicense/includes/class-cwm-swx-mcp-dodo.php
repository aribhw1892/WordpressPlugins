<?php
/**
 * Dodo Payments checkout, subscription status, and customer portal calls.
 *
 * The free trial is recorded here in WordPress. Dodo's create-subscription call
 * needs an email and a billing country, which the host does not send. Checkout
 * collects those when the person adds a card or subscribes.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class CWM_Swx_Mcp_Dodo
{
    const TEST_API = 'https://test.dodopayments.com/';
    const LIVE_API = 'https://live.dodopayments.com/';

    /**
     * @return array{status: string, trial_end: ?string, customer: string}|WP_Error
     */
    public static function find_subscription(string $install_id)
    {
        self::settle_pending($install_id);

        $saved = CWM_Swx_Mcp_Installs::find($install_id);
        $customer = $saved === null ? '' : $saved['customer_id'];
        $subscription_id = $saved === null ? '' : $saved['subscription_id'];
        if ($subscription_id !== '') {
            $subscription = self::retrieve(self::api() . 'subscriptions/' . rawurlencode($subscription_id));
            if (is_wp_error($subscription)) {
                return $subscription;
            }
            if ($subscription === null) {
                CWM_Swx_Mcp_Installs::remember($install_id, $customer, '');
            } else {
                $live_customer = self::customer_id(isset($subscription['customer']) ? $subscription['customer'] : null);
                if ($live_customer !== '') {
                    $customer = $live_customer;
                    if ($customer !== $saved['customer_id']) {
                        CWM_Swx_Mcp_Installs::remember($install_id, $customer, $subscription_id);
                    }
                }

                return self::describe($subscription, $customer);
            }
        }

        return self::local_trial($install_id);
    }

    /**
     * Records a trial with no card and no Dodo customer.
     *
     * @return array{status: string, trial_end: ?string, customer: string}|WP_Error
     */
    public static function start_trial(string $install_id)
    {
        $existing = self::find_subscription($install_id);
        if (is_wp_error($existing)) {
            return $existing;
        }
        if ($existing['status'] !== 'none') {
            return $existing;
        }

        $ready = self::ready();
        if (is_wp_error($ready)) {
            return $ready;
        }

        $settings = CWM_Swx_Mcp_Settings::get();
        $trial_end_gmt = gmdate('Y-m-d H:i:s', time() + ($settings['trial_days'] * DAY_IN_SECONDS));
        if (!CWM_Swx_Mcp_Installs::begin_trial($install_id, $trial_end_gmt)) {
            return new WP_Error('cwm_swx_mcp_store', 'Could not store the trial.', array('status' => 500));
        }

        return self::local_trial($install_id);
    }

    /**
     * Saves the subscription after Dodo sends the browser back, and on later status checks.
     */
    public static function complete_return(WP_REST_Request $request): void
    {
        $install_id = $request->get_param('install_id');
        if (!is_string($install_id) || !self::is_guid($install_id)) {
            return;
        }
        $install_id = strtolower($install_id);

        $subscription_id = $request->get_param('subscription_id');
        if (is_string($subscription_id) && self::is_object_id($subscription_id, 'sub')) {
            $subscription = self::retrieve(self::api() . 'subscriptions/' . rawurlencode($subscription_id));
            if (!is_wp_error($subscription) && is_array($subscription) && self::metadata_install_id($subscription) === $install_id) {
                self::store_subscription($install_id, $subscription);
                return;
            }
        }

        self::settle_pending($install_id);
    }

    /**
     * @return string|WP_Error Checkout or portal URL.
     */
    public static function checkout_url(string $install_id)
    {
        $existing = self::find_subscription($install_id);
        if (is_wp_error($existing)) {
            return $existing;
        }
        if (($existing['status'] === 'trialing' || $existing['status'] === 'active' || $existing['status'] === 'past_due')
            && $existing['customer'] !== '') {
            return self::portal_for_customer($existing['customer']);
        }

        $ready = self::ready();
        if (is_wp_error($ready)) {
            return $ready;
        }

        $settings = CWM_Swx_Mcp_Settings::get();
        $session = self::request('POST', self::api() . 'checkouts', array(
            'product_cart' => array(
                array(
                    'product_id' => $settings['product_id'],
                    'quantity' => 1,
                ),
            ),
            'subscription_data' => array(
                'trial_period_days' => self::checkout_trial_days($existing),
            ),
            'metadata' => array(
                'install_id' => $install_id,
            ),
            'return_url' => self::return_url($install_id),
            'cancel_url' => add_query_arg('cwm_swx_mcp', 'cancel', home_url('/')),
        ));
        if (is_wp_error($session)) {
            return $session;
        }

        $session_id = isset($session['session_id']) ? (string) $session['session_id'] : '';
        if (self::is_object_id($session_id, 'cks') && !CWM_Swx_Mcp_Installs::remember_session($install_id, $session_id)) {
            return new WP_Error('cwm_swx_mcp_store', 'Could not store the checkout session.', array('status' => 500));
        }
        if (empty($session['checkout_url']) || !is_string($session['checkout_url'])) {
            return new WP_Error('cwm_swx_mcp_checkout', 'Dodo Payments did not return a payment page.', array('status' => 502));
        }
        return $session['checkout_url'];
    }

    /**
     * @return string|WP_Error
     */
    public static function portal_url(string $install_id)
    {
        $existing = self::find_subscription($install_id);
        if (is_wp_error($existing)) {
            return $existing;
        }
        if ($existing['customer'] === '') {
            return self::checkout_url($install_id);
        }
        return self::portal_for_customer($existing['customer']);
    }

    /**
     * @return string|WP_Error
     */
    private static function portal_for_customer(string $customer)
    {
        if ($customer === '') {
            return new WP_Error('cwm_swx_mcp_portal', 'No subscription for this install.', array('status' => 404));
        }

        $payload = self::request('POST', self::api() . 'customers/' . rawurlencode($customer) . '/customer-portal/session', array(), array(
            'return_url' => home_url('/'),
        ));
        if (is_wp_error($payload)) {
            return $payload;
        }
        if (empty($payload['link']) || !is_string($payload['link'])) {
            return new WP_Error('cwm_swx_mcp_portal', 'Dodo Payments did not return a billing page.', array('status' => 502));
        }
        return $payload['link'];
    }

    private static function settle_pending(string $install_id): void
    {
        $saved = CWM_Swx_Mcp_Installs::find($install_id);
        if ($saved === null || $saved['pending_session'] === '' || $saved['subscription_id'] !== '') {
            return;
        }

        $session = self::retrieve(self::api() . 'checkouts/' . rawurlencode($saved['pending_session']));
        if (is_wp_error($session) || $session === null) {
            return;
        }

        $payment_id = isset($session['payment_id']) ? (string) $session['payment_id'] : '';
        if (!self::is_object_id($payment_id, 'pay')) {
            return;
        }

        $payment = self::retrieve(self::api() . 'payments/' . rawurlencode($payment_id));
        if (is_wp_error($payment) || $payment === null) {
            return;
        }
        if (self::metadata_install_id($payment) !== $install_id) {
            return;
        }

        $status = isset($payment['status']) ? (string) $payment['status'] : '';
        if ($status === 'failed' || $status === 'cancelled') {
            CWM_Swx_Mcp_Installs::remember_session($install_id, '');
            return;
        }

        $subscription_id = isset($payment['subscription_id']) ? (string) $payment['subscription_id'] : '';
        if (!self::is_object_id($subscription_id, 'sub')) {
            return;
        }

        $subscription = self::retrieve(self::api() . 'subscriptions/' . rawurlencode($subscription_id));
        if (is_wp_error($subscription) || $subscription === null) {
            $customer = self::customer_id(isset($payment['customer']) ? $payment['customer'] : null);
            if ($customer !== '') {
                CWM_Swx_Mcp_Installs::remember($install_id, $customer, $subscription_id);
            }
            return;
        }

        self::store_subscription($install_id, $subscription);
    }

    /**
     * @param array<string, mixed> $subscription
     */
    private static function store_subscription(string $install_id, array $subscription): void
    {
        $subscription_id = isset($subscription['subscription_id']) ? (string) $subscription['subscription_id'] : '';
        $customer = self::customer_id(isset($subscription['customer']) ? $subscription['customer'] : null);
        if (!self::is_object_id($subscription_id, 'sub') || $customer === '') {
            return;
        }
        CWM_Swx_Mcp_Installs::remember($install_id, $customer, $subscription_id);
    }

    /**
     * @return array{status: string, trial_end: ?string, customer: string}
     */
    private static function local_trial(string $install_id): array
    {
        $saved = CWM_Swx_Mcp_Installs::find($install_id);
        $trial_end = $saved === null ? '' : $saved['trial_end_gmt'];
        if ($trial_end === '') {
            return array(
                'status' => 'none',
                'trial_end' => null,
                'customer' => '',
            );
        }

        $end = strtotime($trial_end . ' UTC');
        $iso = $end === false ? null : gmdate('c', $end);
        if ($end !== false && $end > time()) {
            return array(
                'status' => 'trialing',
                'trial_end' => $iso,
                'customer' => '',
            );
        }

        return array(
            'status' => 'canceled',
            'trial_end' => $iso,
            'customer' => '',
        );
    }

    /**
     * @param array<string, mixed> $subscription
     * @return array{status: string, trial_end: ?string, customer: string}
     */
    private static function describe(array $subscription, string $customer): array
    {
        $status = isset($subscription['status']) ? (string) $subscription['status'] : '';
        $in_trial = self::in_trial($subscription);
        $trial_end = $in_trial ? self::trial_end_iso($subscription) : null;

        return array(
            'status' => self::map_status($status, $in_trial),
            'trial_end' => $trial_end,
            'customer' => $customer,
        );
    }

    /**
     * @param array{status: string, trial_end: ?string} $existing
     */
    private static function checkout_trial_days(array $existing): int
    {
        if ($existing['status'] !== 'trialing' || $existing['trial_end'] === null) {
            return 0;
        }

        $end = strtotime($existing['trial_end']);
        if ($end === false || $end <= time()) {
            return 0;
        }

        $days = (int) ceil(($end - time()) / DAY_IN_SECONDS);
        if ($days < 1) {
            return 1;
        }
        if ($days > 10000) {
            return 10000;
        }
        return $days;
    }

    /**
     * Dodo has no trialing status. A free trial is an active subscription whose
     * next bill is still ahead, with either no card or a single zero-amount payment.
     *
     * @param array<string, mixed> $subscription
     */
    private static function in_trial(array $subscription): bool
    {
        if (!self::next_bill_ahead($subscription)) {
            return false;
        }
        if (array_key_exists('has_payment_method', $subscription) && $subscription['has_payment_method'] === false) {
            return true;
        }

        $days = isset($subscription['trial_period_days']) ? (int) $subscription['trial_period_days'] : 0;
        $created = isset($subscription['created_at']) ? strtotime((string) $subscription['created_at']) : false;
        if ($days > 0 && $days <= 10000 && $created !== false && time() < ($created + ($days * DAY_IN_SECONDS))) {
            return true;
        }
        if ($days <= 0) {
            return false;
        }
        return self::free_trial_payment($subscription);
    }

    /**
     * @param array<string, mixed> $subscription
     */
    private static function next_bill_ahead(array $subscription): bool
    {
        if (!isset($subscription['next_billing_date']) || !is_string($subscription['next_billing_date'])) {
            return false;
        }
        $next = strtotime($subscription['next_billing_date']);
        return $next !== false && $next > time();
    }

    /**
     * Dodo's free-trial check: exactly one payment, and its total is 0.
     *
     * @param array<string, mixed> $subscription
     */
    private static function free_trial_payment(array $subscription): bool
    {
        $subscription_id = isset($subscription['subscription_id']) ? (string) $subscription['subscription_id'] : '';
        if (!self::is_object_id($subscription_id, 'sub')) {
            return false;
        }

        $page = self::request('GET', self::api() . 'payments', array(), array(
            'subscription_id' => $subscription_id,
            'page_size' => '10',
        ));
        if (is_wp_error($page) || !isset($page['items']) || !is_array($page['items']) || count($page['items']) !== 1) {
            return false;
        }
        $payment = $page['items'][0];
        return is_array($payment) && isset($payment['total_amount']) && (int) $payment['total_amount'] === 0;
    }

    /**
     * @param array<string, mixed> $subscription
     */
    private static function trial_end_iso(array $subscription): ?string
    {
        if (isset($subscription['next_billing_date']) && is_string($subscription['next_billing_date'])) {
            $next = strtotime($subscription['next_billing_date']);
            if ($next !== false && $next > time()) {
                return gmdate('c', $next);
            }
        }

        $days = isset($subscription['trial_period_days']) ? (int) $subscription['trial_period_days'] : 0;
        $created = isset($subscription['created_at']) ? strtotime((string) $subscription['created_at']) : false;
        if ($created === false || $days <= 0) {
            return null;
        }
        return gmdate('c', $created + ($days * DAY_IN_SECONDS));
    }

    private static function return_url(string $install_id): string
    {
        return add_query_arg(
            array(
                'install_id' => $install_id,
            ),
            rest_url('cwm-mcp/v1/checkout-return')
        );
    }

    /**
     * @return true|WP_Error
     */
    private static function ready()
    {
        $settings = CWM_Swx_Mcp_Settings::get();
        if ($settings['api_key'] === '') {
            return new WP_Error('cwm_swx_mcp_secret', 'The Dodo Payments API key is not set.', array('status' => 503));
        }
        if ($settings['product_id'] === '') {
            return new WP_Error('cwm_swx_mcp_price', 'The Dodo Payments product id is not set.', array('status' => 503));
        }
        return true;
    }

    private static function api(): string
    {
        $settings = CWM_Swx_Mcp_Settings::get();
        return $settings['mode'] === 'live' ? self::LIVE_API : self::TEST_API;
    }

    /**
     * @return array<string, mixed>|null|WP_Error Null when Dodo has no object at this address.
     */
    private static function retrieve(string $url)
    {
        return self::request('GET', $url, array(), array(), true);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $query
     * @return array<string, mixed>|null|WP_Error
     */
    private static function request(string $method, string $url, array $body = array(), array $query = array(), bool $missing_as_null = false)
    {
        $settings = CWM_Swx_Mcp_Settings::get();
        if ($settings['api_key'] === '') {
            return new WP_Error('cwm_swx_mcp_secret', 'The Dodo Payments API key is not set.', array('status' => 503));
        }

        if ($query !== array()) {
            $url = add_query_arg($query, $url);
        }

        $args = array(
            'method' => $method,
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $settings['api_key'],
                'Accept' => 'application/json',
            ),
        );
        if ($body !== array()) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return new WP_Error('cwm_swx_mcp_dodo', 'License service could not reach Dodo Payments.', array('status' => 502));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($missing_as_null && $code === 404) {
            return null;
        }
        if ($code < 200 || $code >= 300 || !is_array($decoded)) {
            $message = 'Dodo Payments rejected the license request.';
            if (is_array($decoded) && isset($decoded['message']) && is_string($decoded['message']) && $decoded['message'] !== '') {
                $message = $decoded['message'];
            }
            return new WP_Error('cwm_swx_mcp_dodo', $message, array('status' => 502));
        }
        return $decoded;
    }

    /**
     * @param mixed $customer
     */
    private static function customer_id($customer): string
    {
        if (!is_array($customer) || !isset($customer['customer_id'])) {
            return '';
        }
        $customer_id = (string) $customer['customer_id'];
        return self::is_object_id($customer_id, 'cus') ? $customer_id : '';
    }

    /**
     * @param array<string, mixed> $object
     */
    private static function metadata_install_id(array $object): string
    {
        if (!isset($object['metadata']) || !is_array($object['metadata']) || !isset($object['metadata']['install_id'])) {
            return '';
        }
        $install_id = strtolower((string) $object['metadata']['install_id']);
        return self::is_guid($install_id) ? $install_id : '';
    }

    private static function is_guid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) === 1;
    }

    private static function is_object_id(string $value, string $prefix): bool
    {
        return preg_match('/^' . preg_quote($prefix, '/') . '_[A-Za-z0-9]+$/', $value) === 1;
    }

    private static function map_status(string $status, bool $in_trial): string
    {
        if ($status === 'active' && $in_trial) {
            return 'trialing';
        }

        switch ($status) {
            case 'active':
                return 'active';
            case 'pending':
            case 'on_hold':
            case 'past_due':
            case 'failed':
                return 'past_due';
            case 'cancelled':
            case 'paused':
            case 'expired':
                return 'canceled';
            default:
                return 'canceled';
        }
    }
}
