<?php
namespace Jankx\Extensions\QrViet\Gateways;

use Jankx\Extensions\PaymentSystem\Gateways\AbstractGateway;
use Jankx\Extensions\PaymentSystem\Models\Transaction;

/**
 * QR Viet gateway.
 *
 * Implements the VietQR Host2Host flow (https://doc.vietqr.vn):
 *
 *  - Get Token      : POST /vqr/api/token_generate (Basic auth) -> Bearer
 *                     token (default lifetime 300 seconds)
 *  - Generate QR    : POST /vqr/api/qr/generate-customer (Bearer). A dynamic
 *                     qrType=0 code returns `qrCode`/`qrLink` the customer
 *                     scans from their banking app (no browser redirect)
 *  - Status query   : POST /vqr/api/transactions/check-order (Bearer) with
 *                     checkSum = MD5(bankAccount + username)
 *  - Transaction Sync: VietQR calls the merchant Transaction Sync webhook
 *                     (`/payment/qrviet/transaction-sync`) with the payment
 *                     result; the extension reconciles the transaction there
 *
 * Payment `content` must be at most 19 characters and free of Vietnamese
 * diacritics and special characters (per the VietQR spec). The order id sent
 * to VietQR (`orderId`) is limited to 13 characters and is persisted on the
 * transaction as `_transaction_id` so the webhook/check-order flow can find
 * it back.
 *
 * @package Jankx\Extensions\QrViet
 */
class QrVietGateway extends AbstractGateway
{
    const API_URL_TEST = 'https://dev.vietqr.org';
    const API_URL_PROD = 'https://api.vietqr.org';

    const PATH_TOKEN = '/vqr/api/token_generate';
    const PATH_GENERATE = '/vqr/api/qr/generate-customer';
    const PATH_CHECK = '/vqr/api/transactions/check-order';

    const MIN_AMOUNT = 1000;

    const ORDER_ID_MAX_LEN = 13;
    const CONTENT_MAX_LEN = 19;

    const TOKEN_LIFETIME = 300;
    const TOKEN_MARGIN = 60;

    const QR_TYPE_DYNAMIC = 0;
    const TRANS_TYPE_CREDIT = 'C';

    protected $slug = 'qrviet';

    protected $displayName = 'QR Viet';

    protected $config = [];

    protected $credentials = [
        'username'      => '',
        'password'      => '',
        'bank_code'     => '',
        'bank_account'  => '',
        'account_name'  => '',
        'terminal_code' => '',
    ];

    protected $token = '';

    protected $tokenExpiresAt = 0;

    public function getName(): string
    {
        return $this->displayName;
    }

    public function initialize(array $parameters): void
    {
        $this->config = wp_parse_args($parameters, [
            'testMode' => false,
            'sandbox_username'      => '',
            'sandbox_password'      => '',
            'sandbox_bank_code'     => '',
            'sandbox_bank_account'  => '',
            'sandbox_account_name'  => '',
            'sandbox_terminal_code' => '',
            'production_username'      => '',
            'production_password'      => '',
            'production_bank_code'     => '',
            'production_bank_account'  => '',
            'production_account_name'  => '',
            'production_terminal_code' => '',
        ]);

        $isTest = !empty($this->config['testMode']);
        $prefix = $isTest ? 'sandbox' : 'production';

        $this->credentials = [
            'username'      => (string) ($this->config["{$prefix}_username"] ?? ''),
            'password'      => (string) ($this->config["{$prefix}_password"] ?? ''),
            'bank_code'     => (string) ($this->config["{$prefix}_bank_code"] ?? ''),
            'bank_account'  => (string) ($this->config["{$prefix}_bank_account"] ?? ''),
            'account_name'  => (string) ($this->config["{$prefix}_account_name"] ?? ''),
            'terminal_code' => (string) ($this->config["{$prefix}_terminal_code"] ?? ''),
        ];

        $this->token = '';
        $this->tokenExpiresAt = 0;
    }

    /**
     * Whether the gateway can be used (needs a VietQR username, password and
     * the bank account the QR code is generated against).
     */
    public function isAvailable(): bool
    {
        return $this->credentials['username'] !== ''
            && $this->credentials['password'] !== ''
            && $this->credentials['bank_code'] !== ''
            && $this->credentials['bank_account'] !== '';
    }

    /**
     * Generate a dynamic VietQR code for the customer to scan.
     *
     * @return array{status: string, qrCode: string, qrLink: string, qrImage: string, transactionId: string, code?: string, raw?: array}
     */
    public function purchase(array $parameters): array
    {
        $transactionId = (string) ($parameters['transactionId'] ?? '');
        $amount = (int) round((float) ($parameters['amount'] ?? 0));

        if ($amount < self::MIN_AMOUNT) {
            return [
                'status'  => 'failed',
                'message' => __('QR Viet amount must be at least 1,000 VND.', 'jankx'),
                'code'    => 'AMOUNT_INVALID',
            ];
        }

        $token = $this->getAccessToken();
        if ($token === '') {
            return [
                'status'  => 'failed',
                'message' => __('Could not authenticate with the VietQR API.', 'jankx'),
                'code'    => 'AUTH_FAILED',
            ];
        }

        $orderId = $this->buildOrderId($transactionId);

        $body = [
            'bankCode'    => $this->credentials['bank_code'],
            'bankAccount' => $this->credentials['bank_account'],
            'content'     => $this->buildContent($parameters),
            'userBankName'=> $this->credentials['account_name'],
            'qrType'      => self::QR_TYPE_DYNAMIC,
            'amount'      => $amount,
            'transType'   => self::TRANS_TYPE_CREDIT,
            'orderId'     => $orderId,
        ];

        if ($this->credentials['terminal_code'] !== '') {
            $body['terminalCode'] = $this->credentials['terminal_code'];
        }

        $response = $this->apiRequestBearer(self::PATH_GENERATE, $body, $token);

        $this->persistOrderRef($transactionId, $orderId);

        if (!$this->isGenerateSuccess($response)) {
            $code = (string) ($response['code'] ?? ($response['errorCode'] ?? ($response['message'] ?? ($response['status'] ?? ''))));
            return [
                'status'  => 'failed',
                'message' => $this->describeError($code),
                'code'    => $code,
                'raw'     => $response,
            ];
        }

        $qrLink = (string) ($response['qrLink'] ?? ($response['link'] ?? ''));

        return [
            'status'        => 'qr',
            'qrCode'        => (string) ($response['qrCode'] ?? ''),
            'qrLink'        => $qrLink,
            'qrImage'       => $qrLink !== '' ? $qrLink : (string) ($response['qrCode'] ?? ''),
            'transactionId' => $orderId,
            'raw'           => $response,
        ];
    }

    /**
     * Reconcile a QR Viet payment result.
     *
     * QR Viet payments are async: the customer pays from their banking app and
     * the final result arrives through the Transaction Sync webhook. There is
     * no browser return, so this method queries the check-order API when an
     * order id is known and otherwise leaves the transaction pending.
     *
     * @return array{status: string, transactionId: string, message: string, code?: string, raw?: array}
     */
    public function completePurchase(array $parameters): array
    {
        $params = is_array($parameters['request_params'] ?? null) ? $parameters['request_params'] : $parameters;

        $orderId = $this->resolveOrderId($params, (string) ($parameters['transactionId'] ?? ''));

        if ($orderId === '') {
            return [
                'status'        => 'pending',
                'transactionId' => '',
                'message'       => __('QR Viet payment is processed asynchronously.', 'jankx'),
                'code'          => 'PENDING',
                'raw'           => $params,
            ];
        }

        $status = $this->queryStatus($orderId);

        if ($status === 'completed') {
            return [
                'status'        => 'success',
                'transactionId' => $orderId,
                'message'       => __('Payment successful.', 'jankx'),
                'code'          => '1',
                'raw'           => $params,
            ];
        }

        if ($status === 'pending') {
            return [
                'status'        => 'pending',
                'transactionId' => $orderId,
                'message'       => __('Payment is being processed.', 'jankx'),
                'code'          => '0',
                'raw'           => $params,
            ];
        }

        return [
            'status'        => 'failed',
            'transactionId' => $orderId,
            'message'       => __('Payment failed.', 'jankx'),
            'code'          => '2',
            'raw'           => $params,
        ];
    }

    /**
     * Refund support depends on the separate VietQR Pro package; the Host2Host
     * API suite documented for this integration exposes no refund endpoint.
     *
     * @return array{status: string, message: string, code: string}
     */
    public function refund(array $parameters): array
    {
        return [
            'status'  => 'failed',
            'message' => __('QR Viet Host2Host does not expose a refund API.', 'jankx'),
            'code'    => 'REFUND_NOT_SUPPORTED',
        ];
    }

    /**
     * VietQR check-order API: reconcile the payment status.
     *
     * @return string 'completed' | 'pending' | 'failed'
     */
    public function queryStatus(string $transactionId): string
    {
        $response = $this->checkOrder($transactionId);

        $this->persistReferenceNumber($transactionId, $response);

        return $this->resolveCheckStatus($response);
    }

    /**
     * Process an incoming VietQR Transaction Sync webhook payload.
     *
     * @param array $data Transaction Sync request body (bankaccount, amount,
     *                    transType, content, orderId, referencenumber, ...).
     * @return array{error: bool, errorReason: ?string, toastMessage: ?string, data: array}
     */
    public function processWebhook(array $data): array
    {
        $orderId = $this->firstValue($data, ['orderId', 'orderid', 'order_id']);
        $amount = (int) round((float) $this->firstValue($data, ['amount', 'amt']));
        $transType = (string) $this->firstValue($data, ['transType', 'trans_type']);

        if ($orderId === '') {
            return [
                'error'       => true,
                'errorReason' => 'E96',
                'toastMessage'=> __('Transaction not found.', 'jankx'),
                'data'        => [],
            ];
        }

        $transaction = Transaction::find($orderId, $this->getSlug());
        if (!$transaction) {
            return [
                'error'       => true,
                'errorReason' => 'E96',
                'toastMessage'=> __('Transaction not found.', 'jankx'),
                'data'        => [],
            ];
        }

        if ($transType !== '' && $transType !== self::TRANS_TYPE_CREDIT) {
            return [
                'error'       => true,
                'errorReason' => 'E03',
                'toastMessage'=> __('Invalid transaction type.', 'jankx'),
                'data'        => [],
            ];
        }

        if ($amount > 0 && $amount !== (int) round($transaction->getAmount())) {
            return [
                'error'       => true,
                'errorReason' => 'E02',
                'toastMessage'=> __('Invalid amount.', 'jankx'),
                'data'        => [],
            ];
        }

        $referenceNumber = (string) $this->firstValue($data, ['referencenumber', 'referenceNumber', 'transactionid', 'transactionId']);
        if ($referenceNumber !== '') {
            $transaction->updateMeta('_reference_number', $referenceNumber);
        }

        $transaction->setStatus(Transaction::STATUS_COMPLETED);

        return [
            'error'       => false,
            'errorReason' => null,
            'toastMessage'=> null,
            'data'        => [
                ['refTransactionId' => $referenceNumber !== '' ? $referenceNumber : $orderId],
            ],
        ];
    }

    /**
     * Admin settings fields (keys map to the saved option array).
     */
    public function getSettingsFields(): array
    {
        return [
            'testMode' => [
                'label'       => __('Test mode (VietQR sandbox)', 'jankx'),
                'type'        => 'checkbox',
                'description' => __('Enable to use the VietQR sandbox environment (dev.vietqr.org).', 'jankx'),
                'default'     => '1',
            ],
            'sandbox_username' => [
                'label'   => __('Username (test)', 'jankx'),
                'type'    => 'text',
                'default' => '',
            ],
            'sandbox_password' => [
                'label'   => __('Password (test)', 'jankx'),
                'type'    => 'password',
                'default' => '',
            ],
            'sandbox_bank_code' => [
                'label'   => __('Bank code (test)', 'jankx'),
                'type'    => 'text',
                'default' => '',
            ],
            'sandbox_bank_account' => [
                'label'   => __('Bank account (test)', 'jankx'),
                'type'    => 'text',
                'default' => '',
            ],
            'sandbox_account_name' => [
                'label'   => __('Account name (test)', 'jankx'),
                'type'    => 'text',
                'default' => '',
            ],
            'sandbox_terminal_code' => [
                'label'       => __('Terminal code (test)', 'jankx'),
                'type'        => 'text',
                'description' => __('Optional, for dynamic payment gestures.', 'jankx'),
                'default'     => '',
            ],
            'production_username' => [
                'label' => __('Username (production)', 'jankx'),
                'type'  => 'text',
            ],
            'production_password' => [
                'label' => __('Password (production)', 'jankx'),
                'type'  => 'password',
            ],
            'production_bank_code' => [
                'label' => __('Bank code (production)', 'jankx'),
                'type'  => 'text',
            ],
            'production_bank_account' => [
                'label' => __('Bank account (production)', 'jankx'),
                'type'  => 'text',
            ],
            'production_account_name' => [
                'label' => __('Account name (production)', 'jankx'),
                'type'  => 'text',
            ],
            'production_terminal_code' => [
                'label' => __('Terminal code (production)', 'jankx'),
                'type'  => 'text',
            ],
            'webhook_token' => [
                'label'       => __('Webhook secret', 'jankx'),
                'type'        => 'password',
                'description' => __('Shared secret used to authenticate the Transaction Sync webhook (Authorization: Bearer).', 'jankx'),
                'default'     => '',
            ],
        ];
    }

    /**
     * Fetch (and cache) a VietQR Bearer token via the Get Token API.
     */
    public function getAccessToken(): string
    {
        if ($this->token !== '' && $this->tokenExpiresAt > time()) {
            return $this->token;
        }

        $url = $this->getApiUrl(self::PATH_TOKEN);
        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->credentials['username'] . ':' . $this->credentials['password']),
                'Content-Type'  => 'application/json; charset=UTF-8',
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return '';
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return '';
        }

        $token = (string) ($data['access_token'] ?? ($data['data']['access_token'] ?? ''));
        if ($token === '') {
            return '';
        }

        $expiresIn = (int) ($data['expires_in'] ?? self::TOKEN_LIFETIME);
        if ($expiresIn <= 0) {
            $expiresIn = self::TOKEN_LIFETIME;
        }

        $this->token = $token;
        $this->tokenExpiresAt = time() + $expiresIn - self::TOKEN_MARGIN;

        return $token;
    }

    protected function apiRequestBearer(string $path, array $body, string $token): array
    {
        $response = wp_remote_post($this->getApiUrl($path), [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json; charset=UTF-8',
            ],
            'body'    => json_encode($body),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return [
                'success' => false,
                'message' => __('Invalid response from QR Viet.', 'jankx'),
            ];
        }

        return $data;
    }

    /**
     * Check the status of an order. Response shape (documented):
     *  - SUCCESS : data[] with orderId, referenceNumber, amount, transType,
     *              timeCreated, timePaid, content, type, refundCount, ...
     *  - FAILED  : message is the error code (section E)
     */
    protected function checkOrder(string $orderId): array
    {
        $token = $this->getAccessToken();
        if ($token === '') {
            return ['status' => 'FAILED', 'message' => 'AUTH_FAILED'];
        }

        $body = [
            'bankAccount' => $this->credentials['bank_account'],
            'type'        => '0',
            'value'       => $orderId,
            'checkSum'    => $this->buildCheckSum(),
        ];

        return $this->apiRequestBearer(self::PATH_CHECK, $body, $token);
    }

    protected function buildCheckSum(): string
    {
        return md5($this->credentials['bank_account'] . $this->credentials['username']);
    }

    /**
     * Map a check-order response to a gateway status.
     */
    protected function resolveCheckStatus(array $response): string
    {
        if ((string) ($response['status'] ?? '') === 'FAILED') {
            return 'failed';
        }

        $data = $response['data'] ?? [];
        if (!is_array($data) || empty($data)) {
            return 'pending';
        }

        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }

            if ((int) ($item['type'] ?? -1) === 6) {
                continue;
            }

            if (array_key_exists('status', $item)) {
                $status = (int) $item['status'];
                if ($status === 1) {
                    return 'completed';
                }
                if ($status === 2) {
                    return 'failed';
                }
                return 'pending';
            }

            if (!empty($item['timePaid'])) {
                return 'completed';
            }
        }

        return 'pending';
    }

    protected function isGenerateSuccess(array $response): bool
    {
        if ((string) ($response['status'] ?? '') === 'SUCCESS') {
            return true;
        }
        return !empty($response['qrLink']) || !empty($response['qrCode']);
    }

    /**
     * Map a VietQR error code (section E) to a human-readable message.
     */
    protected function describeError(string $code): string
    {
        $messages = [
            'E02'  => __('Invalid amount.', 'jankx'),
            'E03'  => __('Invalid transaction type.', 'jankx'),
            'E09'  => __('Invalid content.', 'jankx'),
            'E10'  => __('Invalid account number.', 'jankx'),
            'E24'  => __('No bank found for the given bank code.', 'jankx'),
            'E39'  => __('Invalid checkSum.', 'jankx'),
            'E42'  => __('The account does not have refund permission.', 'jankx'),
            'E43'  => __('Refund failed.', 'jankx'),
            'E44'  => __('Transaction does not exist.', 'jankx'),
            'E45'  => __('Invalid refund amount.', 'jankx'),
            'E46'  => __('Invalid request body or parameter.', 'jankx'),
            'E51'  => __('Invalid bank code.', 'jankx'),
            'E58'  => __('Incorrect type.', 'jankx'),
            'E67'  => __('User information not found.', 'jankx'),
            'E74'  => __('Invalid token.', 'jankx'),
            'E75'  => __('Test callback API service not available.', 'jankx'),
            'E76'  => __('Partner is not registered in the system.', 'jankx'),
            'E77'  => __('Bank account does not match the partner information.', 'jankx'),
            'E95'  => __('Invalid transaction type.', 'jankx'),
            'E96'  => __('Corresponding transaction not found.', 'jankx'),
            'E104' => __('Agent registration information for the partner not found.', 'jankx'),
            'E158' => __('A request is waiting to be processed.', 'jankx'),
            'AUTH_FAILED' => __('Could not authenticate with the VietQR API.', 'jankx'),
        ];

        return $messages[$code] ?? __('QR Viet request failed.', 'jankx');
    }

    protected function getApiUrl(string $path): string
    {
        return ($this->isTestMode() ? self::API_URL_TEST : self::API_URL_PROD) . $path;
    }

    protected function isTestMode(): bool
    {
        return !empty($this->config['testMode']);
    }

    /**
     * Build a VietQR order id: `QRV` + zero-padded transaction id + 2 random
     * chars, capped at the 13-character VietQR limit.
     */
    protected function buildOrderId(string $transactionId): string
    {
        $base = is_numeric($transactionId) && (int) $transactionId > 0
            ? str_pad((string) (int) $transactionId, 6, '0', STR_PAD_LEFT)
            : substr(md5($transactionId . uniqid('', true)), 0, 6);

        $suffix = substr(md5(uniqid('', true)), 0, 2);

        return substr('QRV' . $base . $suffix, 0, self::ORDER_ID_MAX_LEN);
    }

    /**
     * Build the QR payment content: ASCII only (no Vietnamese diacritics), no
     * special characters, max 19 characters (per the VietQR spec).
     */
    protected function buildContent(array $parameters): string
    {
        $description = trim((string) ($parameters['description'] ?? ''));

        if ($description === '') {
            $description = __('Payment', 'jankx');
        }

        $content = $this->removeDiacritics($description);
        $content = strtoupper((string) preg_replace('/[^a-z0-9 ]/i', ' ', $content));
        $content = preg_replace('/\s+/', ' ', trim($content));
        $content = trim((string) $content);

        return substr($content, 0, self::CONTENT_MAX_LEN);
    }

    protected function removeDiacritics(string $string): string
    {
        $map = [
            'à' => 'a', 'á' => 'a', 'ả' => 'a', 'ã' => 'a', 'ạ' => 'a',
            'ă' => 'a', 'ằ' => 'a', 'ắ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a', 'ặ' => 'a',
            'â' => 'a', 'ầ' => 'a', 'ấ' => 'a', 'ẩ' => 'a', 'ẫ' => 'a', 'ậ' => 'a',
            'è' => 'e', 'é' => 'e', 'ẻ' => 'e', 'ẽ' => 'e', 'ẹ' => 'e',
            'ê' => 'e', 'ề' => 'e', 'ế' => 'e', 'ể' => 'e', 'ễ' => 'e', 'ệ' => 'e',
            'ì' => 'i', 'í' => 'i', 'ỉ' => 'i', 'ĩ' => 'i', 'ị' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ỏ' => 'o', 'õ' => 'o', 'ọ' => 'o',
            'ô' => 'o', 'ồ' => 'o', 'ố' => 'o', 'ổ' => 'o', 'ỗ' => 'o', 'ộ' => 'o',
            'ơ' => 'o', 'ờ' => 'o', 'ớ' => 'o', 'ở' => 'o', 'ỡ' => 'o', 'ợ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'ủ' => 'u', 'ũ' => 'u', 'ụ' => 'u',
            'ư' => 'u', 'ừ' => 'u', 'ứ' => 'u', 'ử' => 'u', 'ữ' => 'u', 'ự' => 'u',
            'ỳ' => 'y', 'ý' => 'y', 'ỷ' => 'y', 'ỹ' => 'y', 'ỵ' => 'y',
            'đ' => 'd',
        ];

        return strtr(mb_strtolower($string, 'UTF-8'), $map);
    }

    /**
     * Resolve the VietQR order id from completion/query parameters. Prefers an
     * explicit order id and falls back to the persisted `_transaction_id` when
     * a numeric WordPress transaction id is passed.
     */
    protected function resolveOrderId(array $params, string $transactionId): string
    {
        foreach (['orderId', 'order_id', 'appTransId', 'app_trans_id'] as $key) {
            if (!empty($params[$key])) {
                return (string) $params[$key];
            }
        }

        if (is_numeric($transactionId) && (int) $transactionId > 0) {
            $transaction = new Transaction((int) $transactionId);
            $saved = $transaction->getTransactionId();
            if ($saved !== '') {
                return $saved;
            }
        }

        return '';
    }

    protected function persistOrderRef(string $transactionId, string $orderId): void
    {
        if (!is_numeric($transactionId) || (int) $transactionId <= 0) {
            return;
        }

        $transaction = new Transaction((int) $transactionId);
        if ($transaction->getId()) {
            $transaction->setTransactionId($orderId);
        }
    }

    protected function persistReferenceNumber(string $orderId, array $response): void
    {
        if ((string) ($response['status'] ?? '') === 'FAILED') {
            return;
        }

        $data = $response['data'] ?? [];
        if (!is_array($data) || empty($data)) {
            return;
        }

        $referenceNumber = (string) ($data[0]['referenceNumber'] ?? '');
        if ($referenceNumber === '') {
            return;
        }

        $transaction = Transaction::find($orderId, $this->getSlug());
        if ($transaction) {
            $transaction->updateMeta('_reference_number', $referenceNumber);
        }
    }

    protected function firstValue(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($data[$key])) {
                return (string) $data[$key];
            }
        }
        return '';
    }

    protected function getDefaultIcon(): string
    {
        $asset = __DIR__ . '/../../assets/qr-viet.svg';
        if (is_file($asset)) {
            $icon = (string) file_get_contents($asset);
            if ($icon !== '') {
                return $icon;
            }
        }

        return '<svg width="60" height="60" viewBox="0 0 60 60" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="60" height="60" rx="14" fill="#008A50"/><circle cx="30" cy="29" r="13" stroke="#ffffff" stroke-width="5"/><path d="M39 39 L47 47" stroke="#ffffff" stroke-width="6" stroke-linecap="round"/></svg>';
    }

    protected function getDefaultText(): string
    {
        return $this->displayName;
    }
}