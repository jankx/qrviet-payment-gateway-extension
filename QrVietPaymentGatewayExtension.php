<?php
namespace Jankx\Extensions\QrViet;

use Jankx\Extensions\AbstractExtension;
use Jankx\Extensions\QrViet\Gateways\QrVietGateway;
use Jankx\Extensions\PaymentSystem\Gateways\GatewayManager;

/**
 * QR Viet Payment Gateway Extension.
 *
 * Registers the `qrviet` gateway channel with the payment-system extension:
 *
 *  1. purchase()         -> generates a dynamic VietQR code (qrCode/qrLink)
 *                           the customer scans from their banking app
 *  2. completePurchase() -> reconciles the result via the check-order API
 *                           (QR payments are asynchronous, no browser return)
 *  3. queryStatus()      -> VietQR check-order API (status reconciliation)
 *  4. Transaction Sync   -> REST webhook (`/payment/qrviet/transaction-sync`)
 *                           receives the payment result from VietQR; it is
 *                           authenticated via `Authorization: Bearer` with the
 *                           configured webhook secret
 *
 * @package Jankx\Extensions\QrViet
 */
class QrVietPaymentGatewayExtension extends AbstractExtension
{
    const WEBHOOK_ROUTE = '/payment/qrviet/transaction-sync';

    protected static $instance;

    public function __construct()
    {
        $this->register_autoloader();
        parent::__construct();
    }

    protected function register_autoloader()
    {
        spl_autoload_register(function ($class) {
            $prefix = 'Jankx\\Extensions\\QrViet\\';
            $base_dir = __DIR__ . '/src/';

            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }

            $relative_class = substr($class, $len);
            $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

            if (file_exists($file)) {
                require $file;
            }
        });
    }

    public function init(): void
    {
        self::$instance = $this;
    }

    public static function get_instance(): ?self
    {
        return self::$instance;
    }

    public function register_hooks(): void
    {
        add_action('jankx/payment/register_gateways', [$this, 'registerGateways']);

        add_filter('jankx/payment/gateway/qrviet/default_config', [$this, 'defaultConfig']);

        add_action('admin_init', [$this, 'registerGatewaySettings']);

        add_action('rest_api_init', [$this, 'registerWebhookRoutes']);
    }

    public function registerGatewaySettings(): void
    {
        register_setting('jankx_payment', 'jankx_payment_gateway_qrviet', [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitizeGatewaySettings'],
        ]);
    }

    public function sanitizeGatewaySettings($value): array
    {
        $value = is_array($value) ? $value : [];
        foreach ($value as $key => $item) {
            $value[$key] = is_string($item) ? sanitize_text_field($item) : $item;
        }
        return $value;
    }

    public function registerGateways(): void
    {
        GatewayManager::getInstance()->register('qrviet', QrVietGateway::class);
    }

    public function registerWebhookRoutes(): void
    {
        register_rest_route('jankx/v1', self::WEBHOOK_ROUTE, [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleTransactionSync'],
            'permission_callback' => [$this, 'verifyWebhookPermission'],
        ]);
    }

    /**
     * Permit the VietQR Transaction Sync webhook when the `Authorization:
     * Bearer` header carries the configured webhook secret.
     */
    public function verifyWebhookPermission(): bool
    {
        $token = $this->webhookTokenFromRequest();
        if ($token === '') {
            return false;
        }

        $expected = (string) ($this->branchConfig()['webhook_token'] ?? '');
        if ($expected === '') {
            return false;
        }

        return hash_equals($expected, $token);
    }

    public function handleTransactionSync(\WP_REST_Request $request): \WP_REST_Response
    {
        $gateway = GatewayManager::getInstance()->get('qrviet');

        if (!$gateway) {
            return new \WP_REST_Response([
                'error'       => true,
                'errorReason' => 'E76',
                'toastMessage'=> __('Partner is not registered in the system.', 'jankx'),
                'data'        => [],
            ], 400);
        }

        $gateway->initialize($this->branchConfig());

        return new \WP_REST_Response($gateway->processWebhook($request->get_params()));
    }

    public function defaultConfig(array $config): array
    {
        return array_merge($config, [
            'testMode'             => '1',
            'sandbox_username'     => '',
            'sandbox_password'     => '',
            'sandbox_bank_code'    => '',
            'sandbox_bank_account' => '',
            'sandbox_account_name' => '',
            'sandbox_terminal_code'=> '',
            'webhook_token'        => '',
        ]);
    }

    protected function branchConfig(): array
    {
        $manager = GatewayManager::getInstance();
        if ($manager->hasGateway('qrviet')) {
            return $manager->getConfig('qrviet');
        }
        return array_merge($this->defaultConfig([]), get_option('jankx_payment_gateway_qrviet', []));
    }

    protected function webhookTokenFromRequest(): string
    {
        $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));

        if (stripos($authorization, 'Bearer') === 0) {
            return trim(substr($authorization, 6));
        }

        return '';
    }
}