<?php
/**
 * Stripe settings stored in wp_options as cwm_mcp_license_settings.
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
     * @return array{secret_key: string, price_id: string, trial_days: int}
     */
    public static function get(): array
    {
        $stored = get_option(self::OPTION, array());
        if (!is_array($stored)) {
            $stored = array();
        }

        $secret = isset($stored['secret_key']) ? (string) $stored['secret_key'] : '';
        $price = isset($stored['price_id']) ? (string) $stored['price_id'] : '';
        $days = isset($stored['trial_days']) ? (int) $stored['trial_days'] : 30;

        return array(
            'secret_key' => $secret,
            'price_id' => $price,
            'trial_days' => self::clamp_days($days),
        );
    }

    /**
     * @param mixed $input
     * @return array{secret_key: string, price_id: string, trial_days: int}
     */
    public static function sanitize($input): array
    {
        $current = self::get();
        $input = is_array($input) ? $input : array();

        $secret = isset($input['secret_key']) ? trim((string) $input['secret_key']) : '';
        if ($secret === '') {
            $secret = $current['secret_key'];
        } elseif (!preg_match('/^(sk|rk)_(test|live)_/', $secret)) {
            add_settings_error(self::OPTION, 'secret_key', 'The Stripe secret key must start with sk_test_, sk_live_, rk_test_, or rk_live_.');
            $secret = $current['secret_key'];
        }

        $price = isset($input['price_id']) ? sanitize_text_field((string) $input['price_id']) : '';
        if ($price !== '' && strpos($price, 'price_') !== 0) {
            add_settings_error(self::OPTION, 'price_id', 'The Stripe price id must start with price_.');
            $price = $current['price_id'];
        }

        $days = isset($input['trial_days']) ? (int) $input['trial_days'] : 30;

        return array(
            'secret_key' => $secret,
            'price_id' => $price,
            'trial_days' => self::clamp_days($days),
        );
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = self::get();
        $secret_set = $settings['secret_key'] !== '';
        ?>
        <div class="wrap">
            <h1>SolidWorks MCP</h1>
            <p>Stripe keeps the customer, the trial, and the invoices. This page stores the secret the SolidWorks MCP host is not allowed to see.</p>
            <?php settings_errors(self::OPTION); ?>
            <form action="options.php" method="post">
                <?php settings_fields('cwm_swx_mcp_license'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="cwm-swx-mcp-secret">Stripe secret key</label></th>
                        <td>
                            <input name="<?php echo esc_attr(self::OPTION); ?>[secret_key]" id="cwm-swx-mcp-secret" type="password" class="regular-text" value="" autocomplete="off" />
                            <p class="description"><?php echo $secret_set ? 'A key is saved. Leave this blank to keep it.' : 'Paste the secret key from the Stripe Dashboard.'; ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cwm-swx-mcp-price">Subscription price id</label></th>
                        <td>
                            <input name="<?php echo esc_attr(self::OPTION); ?>[price_id]" id="cwm-swx-mcp-price" type="text" class="regular-text" value="<?php echo esc_attr($settings['price_id']); ?>" />
                            <p class="description">Leave the trial off this Price in Stripe. Trial length is the setting below. A trial on the Price starts again on a later payment.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cwm-swx-mcp-trial">Trial days</label></th>
                        <td>
                            <input name="<?php echo esc_attr(self::OPTION); ?>[trial_days]" id="cwm-swx-mcp-trial" type="number" min="1" max="365" value="<?php echo esc_attr((string) $settings['trial_days']); ?>" />
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * @return array{secret_key: string, price_id: string, trial_days: int}
     */
    private static function defaults(): array
    {
        return array(
            'secret_key' => '',
            'price_id' => '',
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
