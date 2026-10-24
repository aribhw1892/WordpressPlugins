<?php
/**
 * Stripe Checkout, subscription status, and Customer Portal calls.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class CWM_Swx_Mcp_Stripe
{
    const API = 'https://api.stripe.com/v1/';

    /**
     * @return array{status: string, trial_end: ?string, customer: string}|WP_Error
     */
    public static function find_subscription(string $install_id)
    {
        $saved = CWM_Swx_Mcp_Installs::find($install_id);
        $customer = $saved === null ? '' : $saved['customer_id'];
        $subscription_id = $saved === null ? '' : $saved['subscription_id'];
        if ($subscription_id === '') {
            return array(
                'status' => 'none',
                'trial_end' => null,
                'customer' => $customer,
            );
        }

        $subscription = self::retrieve(self::API . 'subscriptions/' . rawurlencode($subscription_id));
        if (is_wp_error($subscription)) {
            return $subscription;
        }
        if ($subscription === null) {
            CWM_Swx_Mcp_Installs::remember($install_id, $customer, '');
            return array(
                'status' => 'none',
                'trial_end' => null,
                'customer' => $customer,
            );
        }

        $live_customer = self::customer_id(isset($subscription['customer']) ? $subscription['customer'] : '');
        if ($live_customer !== '') {
            $customer = $live_customer;
        }

        return array(
            'status' => self::map_status(isset($subscription['status']) ? (string) $subscription['status'] : ''),
            'trial_end' => self::trial_end(isset($subscription['trial_end']) ? $subscription['trial_end'] : null),
            'customer' => $customer,
        );
    }

    /**
     * Starts a trial with no card. A machine that already has a subscription is left unchanged.
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

        $settings = CWM_Swx_Mcp_Settings::get();
        if ($settings['price_id'] === '') {
            return new WP_Error('cwm_swx_mcp_price', 'The Stripe price id is not set.', array('status' => 503));
        }

        $customer = $existing['customer'];
        if ($customer === '') {
            $created = self::request('POST', self::API . 'customers', array(
                'metadata' => array(
                    'install_id' => $install_id,
                ),
            ), 'cwm-swx-mcp-customer-' . $install_id);
            if (is_wp_error($created)) {
                return $created;
            }
            $customer = self::object_id(isset($created['id']) ? $created['id'] : '', 'cus');
        }
        if ($customer === '') {
            return new WP_Error('cwm_swx_mcp_customer', 'Stripe did not return a customer.', array('status' => 502));
        }
        if (!CWM_Swx_Mcp_Installs::remember($install_id, $customer, '')) {
            return new WP_Error('cwm_swx_mcp_store', 'Could not store the Stripe customer.', array('status' => 500));
        }

        $subscription = self::request('POST', self::API . 'subscriptions', array(
            'customer' => $customer,
            'items' => array(
                array(
                    'price' => $settings['price_id'],
                ),
            ),
            'trial_period_days' => $settings['trial_days'],
            'metadata' => array(
                'install_id' => $install_id,
            ),
            'trial_settings' => array(
                'end_behavior' => array(
                    'missing_payment_method' => 'cancel',
                ),
            ),
        ), 'cwm-swx-mcp-trial-' . $install_id);
        if (is_wp_error($subscription)) {
            return $subscription;
        }

        $subscription_id = self::object_id(isset($subscription['id']) ? $subscription['id'] : '', 'sub');
        if ($subscription_id === '' || !CWM_Swx_Mcp_Installs::remember($install_id, $customer, $subscription_id)) {
            return new WP_Error('cwm_swx_mcp_store', 'Could not store the Stripe subscription.', array('status' => 500));
        }

        return array(
            'status' => self::map_status(isset($subscription['status']) ? (string) $subscription['status'] : ''),
            'trial_end' => self::trial_end(isset($subscription['trial_end']) ? $subscription['trial_end'] : null),
            'customer' => $customer,
        );
    }

    /**
     * Saves the subscription created by Checkout. The browser lands here after payment.
     */
    public static function remember_checkout_session(string $session_id): void
    {
        if (!preg_match('/^cs_(test_|live_)?[A-Za-z0-9]+$/', $session_id)) {
            return;
        }

        $session = self::retrieve(self::API . 'checkout/sessions/' . rawurlencode($session_id));
        if (is_wp_error($session) || $session === null) {
            return;
        }

        $install_id = isset($session['client_reference_id']) ? (string) $session['client_reference_id'] : '';
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $install_id)) {
            return;
        }

        $customer = self::object_id(isset($session['customer']) ? $session['customer'] : '', 'cus');
        $subscription_id = self::object_id(isset($session['subscription']) ? $session['subscription'] : '', 'sub');
        if ($customer === '' || $subscription_id === '') {
            return;
        }

        CWM_Swx_Mcp_Installs::remember($install_id, $customer, $subscription_id);
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

        $settings = CWM_Swx_Mcp_Settings::get();
        if ($settings['price_id'] === '') {
            return new WP_Error('cwm_swx_mcp_price', 'The Stripe price id is not set.', array('status' => 503));
        }

        $subscription_data = array(
            'metadata' => array(
                'install_id' => $install_id,
            ),
        );
        if ($existing['status'] === 'none') {
            $subscription_data['trial_period_days'] = $settings['trial_days'];
        }

        $session = array(
            'mode' => 'subscription',
            'client_reference_id' => $install_id,
            'success_url' => self::checkout_success_url(),
            'cancel_url' => add_query_arg('cwm_swx_mcp', 'cancel', home_url('/')),
            'payment_method_collection' => 'always',
            'line_items' => array(
                array(
                    'price' => $settings['price_id'],
                    'quantity' => 1,
                ),
            ),
            'subscription_data' => $subscription_data,
            'metadata' => array(
                'install_id' => $install_id,
            ),
        );
        if ($existing['customer'] !== '') {
            $session['customer'] = $existing['customer'];
        }

        $payload = self::request('POST', self::API . 'checkout/sessions', $session);
        if (is_wp_error($payload)) {
            return $payload;
        }
        if (empty($payload['url']) || !is_string($payload['url'])) {
            return new WP_Error('cwm_swx_mcp_checkout', 'Stripe did not return a payment page.', array('status' => 502));
        }
        return $payload['url'];
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

        $payload = self::request('POST', self::API . 'billing_portal/sessions', array(
            'customer' => $customer,
            'return_url' => home_url('/'),
        ));
        if (is_wp_error($payload)) {
            return $payload;
        }
        if (empty($payload['url']) || !is_string($payload['url'])) {
            return new WP_Error('cwm_swx_mcp_portal', 'Stripe did not return a billing page.', array('status' => 502));
        }
        return $payload['url'];
    }

    private static function checkout_success_url(): string
    {
        $return = rest_url('cwm-mcp/v1/checkout-return');
        $join = strpos($return, '?') === false ? '?' : '&';
        return $return . $join . 'session_id={CHECKOUT_SESSION_ID}';
    }

    /**
     * @return array<string, mixed>|null|WP_Error Null when Stripe has no object at this address.
     */
    private static function retrieve(string $url)
    {
        return self::request('GET', $url, array(), '', true);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null|WP_Error
     */
    private static function request(string $method, string $url, array $body = array(), string $idempotency = '', bool $missing_as_null = false)
    {
        $settings = CWM_Swx_Mcp_Settings::get();
        if ($settings['secret_key'] === '') {
            return new WP_Error('cwm_swx_mcp_secret', 'The Stripe secret key is not set.', array('status' => 503));
        }

        $headers = array(
            'Authorization' => 'Bearer ' . $settings['secret_key'],
        );
        if ($idempotency !== '') {
            $headers['Idempotency-Key'] = $idempotency;
        }

        $args = array(
            'method' => $method,
            'timeout' => 20,
            'headers' => $headers,
        );
        if ($body !== array()) {
            $args['body'] = $body;
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return new WP_Error('cwm_swx_mcp_stripe', 'License service could not reach Stripe.', array('status' => 502));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($missing_as_null && $code === 404) {
            return null;
        }
        if ($code < 200 || $code >= 300 || !is_array($decoded)) {
            $message = 'Stripe rejected the license request.';
            if (is_array($decoded) && isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
                $message = $decoded['error']['message'];
            }
            return new WP_Error('cwm_swx_mcp_stripe', $message, array('status' => 502));
        }
        return $decoded;
    }

    /**
     * @param mixed $customer
     */
    private static function customer_id($customer): string
    {
        return self::object_id($customer, 'cus');
    }

    /**
     * @param mixed $value
     */
    private static function object_id($value, string $prefix): string
    {
        if (is_array($value) && isset($value['id'])) {
            $value = $value['id'];
        }
        if (!is_string($value) || !preg_match('/^' . preg_quote($prefix, '/') . '_[A-Za-z0-9]+$/', $value)) {
            return '';
        }
        return $value;
    }

    /**
     * @param mixed $trial_end
     */
    private static function trial_end($trial_end): ?string
    {
        if (!is_numeric($trial_end) || (int) $trial_end <= 0) {
            return null;
        }
        return gmdate('c', (int) $trial_end);
    }

    private static function map_status(string $status): string
    {
        switch ($status) {
            case 'trialing':
                return 'trialing';
            case 'active':
                return 'active';
            case 'past_due':
            case 'unpaid':
            case 'incomplete':
                return 'past_due';
            case 'canceled':
            case 'incomplete_expired':
            case 'paused':
                return 'canceled';
            default:
                return 'canceled';
        }
    }
}
