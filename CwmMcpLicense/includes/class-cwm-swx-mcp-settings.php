<?php
/**
 * Dodo Payments settings stored in wp_options as cwm_mcp_license_settings.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class CWM_Swx_Mcp_Settings
{
    const OPTION = 'cwm_mcp_license_settings';

    public static function register(): void
    {
        register_setting(
            'cwm_swx_mcp_license',
            self::OPTION,
            array(
                'type' => 'array',
                'sanitize_callback' => array(self::class, 'sanitize'),
                'default' => self::defaults(),
            )
        );
    }

    /**
     * @return array{api_key: string, product_id: string, mode: string, trial_days: int}
     */
    public static function get(): array
    {
        $stored = get_option(self::OPTION, array());
        if (!is_array($stored)) {
            $stored = array();
        }

        $secret = isset($stored['api_key']) ? (string) $stored['api_key'] : '';
        if ($secret === '' && isset($stored['secret_key'])) {
            $legacy = (string) $stored['secret_key'];
            if (!preg_match('/^(sk|rk)_/', $legacy)) {
                $secret = $legacy;
            }
        }

        $product = isset($stored['product_id']) ? (string) $stored['product_id'] : '';
        if ($product === '' && isset($stored['price_id'])) {
            $legacy_price = (string) $stored['price_id'];
            if (strpos($legacy_price, 'price_') !== 0 && strpos($legacy_price, 'pdt_') === 0) {
                $product = $legacy_price;
            }
        }

        $mode = isset($stored['mode']) && $stored['mode'] === 'live' ? 'live' : 'test';
        $days = isset($stored['trial_days']) ? (int) $stored['trial_days'] : 30;

        return array(
            'api_key' => $secret,
            'product_id' => $product,
            'mode' => $mode,
            'trial_days' => self::clamp_days($days),
        );
    }

    /**
     * @param mixed $input
     * @return array{api_key: string, product_id: string, mode: string, trial_days: int}
     */
    public static function sanitize($input): array
    {
        $current = self::get();
        $input = is_array($input) ? $input : array();

        $secret = isset($input['api_key']) ? trim((string) $input['api_key']) : '';
        if ($secret === '') {
            $secret = $current['api_key'];
        } elseif (!preg_match('/^[A-Za-z0-9._\-]{20,300}$/', $secret) || preg_match('/^(sk|rk)_/', $secret)) {
            add_settings_error(self::OPTION, 'api_key', 'Paste the Dodo Payments API key for the mode selected below.');
            $secret = $current['api_key'];
        }

        $product = isset($input['product_id']) ? sanitize_text_field((string) $input['product_id']) : '';
        if ($product !== '' && !preg_match('/^pdt_[A-Za-z0-9_]+$/', $product)) {
            add_settings_error(self::OPTION, 'product_id', 'The Dodo Payments product id must start with pdt_.');
            $product = $current['product_id'];
        }

        $mode = isset($input['mode']) && $input['mode'] === 'live' ? 'live' : 'test';
        $days = isset($input['trial_days']) ? (int) $input['trial_days'] : 30;

        return array(
            'api_key' => $secret,
            'product_id' => $product,
            'mode' => $mode,
            'trial_days' => self::clamp_days($days),
        );
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = self::get();
        $secret_set = $settings['api_key'] !== '';
        ?>
        <div class="wrap">
            <h1>SolidWorks MCP</h1>
            <p>Dodo Payments keeps the customer, the card, and the invoices. The free trial is recorded in WordPress until the person adds a card. This page stores the API key the SolidWorks MCP host is not allowed to see.</p>
            <p><a href="<?php echo esc_url(CWM_Swx_Mcp_Dashboard::public_url()); ?>">Open the analytics dashboard</a>. It is a separate page and only an administrator can open it.</p>
            <?php settings_errors(self::OPTION); ?>
            <form action="options.php" method="post">
                <?php settings_fields('cwm_swx_mcp_license'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="cwm-swx-mcp-mode">Mode</label></th>
                        <td>
                            <select name="<?php echo esc_attr(self::OPTION); ?>[mode]" id="cwm-swx-mcp-mode">
                                <option value="test" <?php selected($settings['mode'], 'test'); ?>>Test</option>
                                <option value="live" <?php selected($settings['mode'], 'live'); ?>>Live</option>
                            </select>
                            <p class="description">Test calls test.dodopayments.com. Live calls live.dodopayments.com. The API key must belong to the same mode.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cwm-swx-mcp-secret">Dodo Payments API key</label></th>
                        <td>
                            <input name="<?php echo esc_attr(self::OPTION); ?>[api_key]" id="cwm-swx-mcp-secret" type="password" class="regular-text" value="" autocomplete="off" />
                            <p class="description"><?php echo $secret_set ? 'A key is saved. Leave this blank to keep it.' : 'Paste the API key from Developer → API Keys.'; ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cwm-swx-mcp-price">Product id</label></th>
                        <td>
                            <input name="<?php echo esc_attr(self::OPTION); ?>[product_id]" id="cwm-swx-mcp-price" type="text" class="regular-text" value="<?php echo esc_attr($settings['product_id']); ?>" />
                            <p class="description">The subscription product id. It starts with pdt_. Leave Trial Amount empty on that product. Check Start the trial without a card.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cwm-swx-mcp-trial">Trial days</label></th>
                        <td>
                            <input name="<?php echo esc_attr(self::OPTION); ?>[trial_days]" id="cwm-swx-mcp-trial" type="number" min="1" max="365" value="<?php echo esc_attr((string) $settings['trial_days']); ?>" />
                            <p class="description">Sent as subscription_data.trial_period_days when a card is added during the trial. After the trial this plugin sends 0, so a product trial does not start again.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * @return array{api_key: string, product_id: string, mode: string, trial_days: int}
     */
    private static function defaults(): array
    {
        return array(
            'api_key' => '',
            'product_id' => '',
            'mode' => 'test',
            'trial_days' => 30,
        );
    }

    private static function clamp_days(int $days): int
    {
        if ($days < 1) {
            return 1;
        }
        if ($days > 365) {
            return 365;
        }
        return $days;
    }
}
