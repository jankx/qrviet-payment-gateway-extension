<?php
namespace Jankx\Extensions\QrViet;

use Jankx\Extensions\AbstractExtension;
use Jankx\Extensions\QrViet\Gateways\QrVietGateway;
use Jankx\Extensions\PaymentSystem\Gateways\GatewayManager;
use Jankx\Extensions\PaymentSystem\Models\Transaction;

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

        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);

        add_filter('jankx/ecommerce/order_detail/after_payment_info', [$this, 'renderOrderDetailQr'], 20, 2);

        add_filter('jankx/ecommerce/qr_payment/bank_info', [$this, 'provideQrBankInfo'], 10, 2);
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

    /**
     * Supply bank info to the checkout QR modal for the qrviet gateway.
     *
     * @param array  $info    Existing bank info (from other filters).
     * @param string $gateway Gateway slug being processed.
     * @return array
     */
    public function provideQrBankInfo(array $info, string $gateway): array
    {
        if ($gateway !== 'qrviet') {
            return $info;
        }

        $config = $this->branchConfig();
        $isTest = !empty($config['testMode']);
        $prefix = $isTest ? 'sandbox' : 'production';

        $bankCode    = (string) ($config["${prefix}_bank_code"] ?? '');
        $bankAccount = (string) ($config["${prefix}_bank_account"] ?? '');
        $accountName = (string) ($config["${prefix}_account_name"] ?? '');

        return [
            'bank_code'    => $bankCode,
            'bank_name'    => $bankCode,
            'bank_account' => $bankAccount,
            'account_name' => $accountName,
        ];
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

    /**
     * Load the QR stylesheet only on the account (order detail) pages.
     */
    public function enqueueAssets(): void
    {
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if (strpos($requestUri, '/tai-khoan-cua-toi/') === false) {
            return;
        }

        wp_enqueue_style(
            'jankx-qrviet',
            trailingslashit($this->get_extension_url()) . 'assets/frontend.css',
            [],
            '1.0.0'
        );
    }

    /**
     * Render the dynamic VietQR (from the API-generated transaction) on the
     * order detail page so the customer can scan it to pay.
     *
     * @param string $content Existing output appended by another extension.
     * @param mixed  $order   The Jankx order being rendered.
     */
    public function renderOrderDetailQr(string $content, $order): string
    {
        $orderClass = 'Jankx\Extensions\Ecommerce\Order\Order';
        if (!class_exists($orderClass) || !($order instanceof $orderClass)) {
            return $content;
        }

        if ($order->getPaymentMethod() !== 'qrviet') {
            return $content;
        }

        $status = $order->getStatus();
        if (!in_array($status, [$orderClass::STATUS_PENDING, $orderClass::STATUS_PROCESSING], true)) {
            return $content;
        }

        $transactionId = $order->getPaymentTransactionId();
        if (!$transactionId) {
            return $content;
        }

        $transaction = new Transaction((int) $transactionId);
        if (!$transaction->getId()) {
            return $content;
        }

        $qrImage = $transaction->getMeta('_qr_image');
        if ($qrImage === '') {
            return $content;
        }

        $orderNumber = $order->getOrderNumber();
        $amount = number_format((int) $order->getTotal(), 0, ',', '.') . ' ₫';
        $qrCode = $transaction->getMeta('_qr_code');

        $output = '<div class="jankx-od-card jankx-od-card--qrviet">';
        $output .= '<div class="jankx-od-card-head">';
        $output .= '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="8" height="8" rx="1"/><rect x="14" y="2" width="8" height="8" rx="1"/><rect x="2" y="14" width="8" height="8" rx="1"/><rect x="14" y="14" width="4" height="4" rx="0.5"/><line x1="22" y1="14" x2="22" y2="22"/><line x1="14" y1="22" x2="22" y2="22"/></svg>';
        $output .= '<h3 class="jankx-od-card-title">' . esc_html__('Quét mã QR để thanh toán', 'jankx') . '</h3>';
        $output .= '</div>';
        $output .= '<div class="jankx-qrviet-content">';
        $output .= '<div class="jankx-qrviet-image">';
        $output .= '<img src="' . esc_url($qrImage) . '" alt="VietQR - ' . esc_attr($orderNumber) . '" width="280" height="280" loading="lazy">';
        $output .= '</div>';
        $output .= '<div class="jankx-qrviet-info">';
        $output .= '<p><strong>' . esc_html__('Đơn hàng:', 'jankx') . '</strong> ' . esc_html($orderNumber) . '</p>';
        $output .= '<p><strong>' . esc_html__('Số tiền:', 'jankx') . '</strong> <span class="jankx-qrviet-amount">' . esc_html($amount) . '</span></p>';
        if ($qrCode !== '') {
            $output .= '<p><strong>' . esc_html__('Mã giao dịch:', 'jankx') . '</strong> <code>' . esc_html($qrCode) . '</code></p>';
        }
        $output .= '<p class="description">' . esc_html__('Mở ứng dụng ngân hàng → Quét mã QR → Xác nhận thanh toán.', 'jankx') . '</p>';
        $output .= '<p class="description">' . esc_html__('Sau khi thanh toán, trạng thái đơn hàng sẽ tự động cập nhật. Bạn có thể tải lại trang để kiểm tra.', 'jankx') . '</p>';
        $output .= '</div>';
        $output .= '</div>';
        $output .= '</div>';

        return $content . $output;
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