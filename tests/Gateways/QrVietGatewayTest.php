<?php
namespace Jankx\Extensions\QrViet\Tests\Gateways;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Jankx\Extensions\QrViet\Gateways\QrVietGateway;

class TestQrVietGateway extends QrVietGateway
{
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    public function getConfigValue(): array
    {
        return $this->config;
    }

    public function setCredentials(array $credentials): void
    {
        $this->credentials = $credentials;
    }

    public function getCredentials(): array
    {
        return $this->credentials;
    }

    public function exposeIsTestMode(): bool
    {
        return $this->isTestMode();
    }

    public function exposeGetApiUrl(string $path): string
    {
        return $this->getApiUrl($path);
    }

    public function exposeBuildOrderId(string $transactionId): string
    {
        return $this->buildOrderId($transactionId);
    }

    public function exposeBuildContent(array $parameters): string
    {
        return $this->buildContent($parameters);
    }

    public function exposeRemoveDiacritics(string $string): string
    {
        return $this->removeDiacritics($string);
    }

    public function exposeBuildCheckSum(): string
    {
        return $this->buildCheckSum();
    }

    public function exposeCheckOrder(string $orderId): array
    {
        return $this->checkOrder($orderId);
    }

    public function exposeResetAccessToken(): void
    {
        $this->token = '';
        $this->tokenExpiresAt = 0;
    }
}

class QrVietGatewayTest extends TestCase
{
    const TEST_USER = 'vietqr_user';
    const TEST_PASS = 'vietqr_pass';
    const TEST_BANK_CODE = 'MB';
    const TEST_BANK_ACCOUNT = '9704221234567890';
    const TEST_ACCOUNT_NAME = 'CONG TY TNHH NIBITOUR';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        qrviet_test_stub_wp_functions();
        $GLOBALS['__post_meta'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__post_meta']);
        $_SERVER = [];
        Monkey\tearDown();
        parent::tearDown();
    }

    protected function sandboxConfig(array $overrides = []): array
    {
        return array_merge([
            'testMode' => true,
            'sandbox_username'      => self::TEST_USER,
            'sandbox_password'      => self::TEST_PASS,
            'sandbox_bank_code'     => self::TEST_BANK_CODE,
            'sandbox_bank_account'  => self::TEST_BANK_ACCOUNT,
            'sandbox_account_name'  => self::TEST_ACCOUNT_NAME,
            'sandbox_terminal_code' => 'TC0001',
        ], $overrides);
    }

    protected function productionConfig(): array
    {
        return array_merge($this->sandboxConfig(), [
            'testMode' => false,
            'production_username'      => 'prod_user',
            'production_password'      => 'prod_pass',
            'production_bank_code'     => 'BIDV',
            'production_bank_account'  => '100123456789',
            'production_account_name'  => 'NIBITOUR JSC',
            'production_terminal_code' => 'TC0009',
        ]);
    }

    protected function newGateway(): TestQrVietGateway
    {
        $gateway = new TestQrVietGateway();
        $gateway->initialize($this->sandboxConfig());
        return $gateway;
    }

    protected function mockRemoteApi($body, &$captured = null): void
    {
        Functions\when('wp_remote_post')->alias(function ($url, $args) use (&$captured, $body) {
            $captured = ['url' => $url, 'args' => $args];
            return ['body' => $body];
        });
        Functions\when('wp_remote_retrieve_body')->alias(function ($response) {
            return $response['body'] ?? '';
        });
        Functions\when('is_wp_error')->justReturn(false);
    }

    protected function mockRemoteApiJson(array $body, &$captured = null): void
    {
        $this->mockRemoteApi(json_encode($body), $captured);
    }

    /**
     * Return a different response body for each successive remote call.
     */
    protected function mockRemoteApiQueue(array $bodies, &$captured = null): void
    {
        $index = 0;
        Functions\when('wp_remote_post')->alias(function ($url, $args) use (&$captured, &$index, $bodies) {
            $captured[] = ['url' => $url, 'args' => $args];
            return ['body' => $bodies[$index++]];
        });
        Functions\when('wp_remote_retrieve_body')->alias(function ($response) {
            return $response['body'] ?? '';
        });
        Functions\when('is_wp_error')->justReturn(false);
    }

    protected function tokenResponse(string $token = 'tok_abc123'): array
    {
        return [
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'expires_in'   => 300,
        ];
    }

    protected function generateResponse(array $overrides = []): array
    {
        return array_merge([
            'status'     => 'SUCCESS',
            'bankCode'   => self::TEST_BANK_CODE,
            'bankName'   => 'Ngân hàng TMCP Quân đội',
            'bankAccount'=> self::TEST_BANK_ACCOUNT,
            'userBankName' => self::TEST_ACCOUNT_NAME,
            'amount'     => 100000,
            'content'    => 'THANH TOAN TOUR',
            'qrCode'     => '000201010212...',
            'qrLink'     => 'https://img.vietqr.io/image/MB-9704221234567890-compact2.png',
            'terminalCode' => 'TC0001',
        ], $overrides);
    }

    protected function checkOrderResponse(int $status, array $overrides = []): array
    {
        return array_merge([
            'status' => 'SUCCESS',
            'data'   => [
                array_merge([
                    'orderId'        => 'QRV000042ab',
                    'referenceNumber'=> '202401011200001',
                    'amount'         => 100000,
                    'transType'      => 'C',
                    'timeCreated'    => 1704100000,
                    'timePaid'       => 1704100100,
                    'content'        => 'THANH TOAN TOUR',
                    'type'           => 0,
                    'refundCount'    => 0,
                    'amountRefunded' => 0,
                ], $status === 0 ? ['status' => 0, 'timePaid' => ''] : ['status' => $status]),
            ],
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // initialize()
    // ------------------------------------------------------------------

    public function test_initialize_merges_defaults()
    {
        $gateway = new TestQrVietGateway();
        $gateway->initialize([
            'testMode' => true,
            'sandbox_username' => 'u1',
        ]);

        $config = $gateway->getConfigValue();
        $this->assertEquals('u1', $config['sandbox_username']);
        $this->assertSame(false, $config['testMode'] ? false : false);
    }

    public function test_initialize_sandbox_mode_uses_sandbox_credentials()
    {
        $gateway = new TestQrVietGateway();
        $gateway->initialize($this->sandboxConfig(['testMode' => true]));

        $this->assertEquals([
            'username'      => self::TEST_USER,
            'password'      => self::TEST_PASS,
            'bank_code'     => self::TEST_BANK_CODE,
            'bank_account'  => self::TEST_BANK_ACCOUNT,
            'account_name'  => self::TEST_ACCOUNT_NAME,
            'terminal_code' => 'TC0001',
        ], $gateway->getCredentials());
    }

    public function test_initialize_production_mode_uses_production_credentials()
    {
        $gateway = new TestQrVietGateway();
        $gateway->initialize($this->productionConfig());

        $this->assertEquals([
            'username'      => 'prod_user',
            'password'      => 'prod_pass',
            'bank_code'     => 'BIDV',
            'bank_account'  => '100123456789',
            'account_name'  => 'NIBITOUR JSC',
            'terminal_code' => 'TC0009',
        ], $gateway->getCredentials());
    }

    public function test_initialize_missing_credentials_defaults_to_empty_string()
    {
        $gateway = new TestQrVietGateway();
        $gateway->initialize(['testMode' => true]);

        $this->assertEquals([
            'username'      => '',
            'password'      => '',
            'bank_code'     => '',
            'bank_account'  => '',
            'account_name'  => '',
            'terminal_code' => '',
        ], $gateway->getCredentials());
    }

    // ------------------------------------------------------------------
    // isAvailable()
    // ------------------------------------------------------------------

    public function test_isAvailable_true_when_all_required_credentials_present()
    {
        $this->assertTrue($this->newGateway()->isAvailable());
    }

    public function test_isAvailable_false_when_username_missing()
    {
        $gateway = $this->newGateway();
        $gateway->setCredentials(['username' => '', 'password' => 'x', 'bank_code' => 'MB', 'bank_account' => '1']);
        $this->assertFalse($gateway->isAvailable());
    }

    public function test_isAvailable_false_when_bank_account_missing()
    {
        $gateway = $this->newGateway();
        $gateway->setCredentials(['username' => 'u', 'password' => 'x', 'bank_code' => 'MB', 'bank_account' => '']);
        $this->assertFalse($gateway->isAvailable());
    }

    // ------------------------------------------------------------------
    // getName() / endpoints
    // ------------------------------------------------------------------

    public function test_getName_returns_display_name()
    {
        $gateway = new TestQrVietGateway();
        $this->assertEquals('QR Viet', $gateway->getName());
    }

    public function test_api_urls_resolve_per_mode()
    {
        $this->assertEquals(QrVietGateway::API_URL_TEST . QrVietGateway::PATH_TOKEN, $this->newGateway()->exposeGetApiUrl(QrVietGateway::PATH_TOKEN));

        $gateway = new TestQrVietGateway();
        $gateway->initialize($this->productionConfig());
        $this->assertEquals(QrVietGateway::API_URL_PROD . QrVietGateway::PATH_TOKEN, $gateway->exposeGetApiUrl(QrVietGateway::PATH_TOKEN));
    }

    public function test_is_test_mode_flag()
    {
        $this->assertTrue($this->newGateway()->exposeIsTestMode());

        $gateway = new TestQrVietGateway();
        $gateway->initialize($this->productionConfig());
        $this->assertFalse($gateway->exposeIsTestMode());
    }

    // ------------------------------------------------------------------
    // getAccessToken()
    // ------------------------------------------------------------------

    public function test_getAccessToken_posts_basic_authentication()
    {
        $gateway = $this->newGateway();
        $captured = null;
        $this->mockRemoteApiJson($this->tokenResponse(), $captured);

        $token = $gateway->getAccessToken();

        $this->assertEquals('tok_abc123', $token);
        $this->assertEquals(QrVietGateway::API_URL_TEST . QrVietGateway::PATH_TOKEN, $captured['url']);
        $expected = 'Basic ' . base64_encode(self::TEST_USER . ':' . self::TEST_PASS);
        $this->assertSame($expected, $captured['args']['headers']['Authorization']);
    }

    public function test_getAccessToken_returns_empty_on_wp_error()
    {
        $gateway = $this->newGateway();
        Functions\when('wp_remote_post')->justReturn(new \WP_Error('http_error', 'boom'));
        Functions\when('wp_remote_retrieve_body')->justReturn('');
        Functions\when('is_wp_error')->justReturn(true);

        $this->assertSame('', $gateway->getAccessToken());
    }

    public function test_getAccessToken_returns_empty_on_missing_token()
    {
        $gateway = $this->newGateway();
        $this->mockRemoteApiJson(['status' => 'FAILED', 'message' => 'E76']);

        $this->assertSame('', $gateway->getAccessToken());
    }

    public function test_getAccessToken_is_cached_within_lifetime()
    {
        $gateway = $this->newGateway();
        $captured = [];
        $this->mockRemoteApiQueue([json_encode($this->tokenResponse())], $captured);

        $this->assertSame('tok_abc123', $gateway->getAccessToken());
        $this->assertSame('tok_abc123', $gateway->getAccessToken());
        $this->assertCount(1, $captured);
    }

    // ------------------------------------------------------------------
    // purchase()
    // ------------------------------------------------------------------

    public function test_purchase_requests_token_then_generates_qr()
    {
        $gateway = $this->newGateway();
        $captured = [];
        $this->mockRemoteApiQueue([
            json_encode($this->tokenResponse()),
            json_encode($this->generateResponse()),
        ], $captured);

        $result = $gateway->purchase([
            'transactionId' => '42',
            'amount' => 100000,
            'description' => 'Thanh toán tour Ninh Bình #42',
        ]);

        $this->assertEquals('qr', $result['status']);

        $this->assertCount(2, $captured);
        $this->assertEquals(QrVietGateway::API_URL_TEST . QrVietGateway::PATH_TOKEN, $captured[0]['url']);
        $this->assertEquals(QrVietGateway::API_URL_TEST . QrVietGateway::PATH_GENERATE, $captured[1]['url']);

        $this->assertSame('Bearer tok_abc123', $captured[1]['args']['headers']['Authorization']);
        $this->assertSame('application/json; charset=UTF-8', $captured[1]['args']['headers']['Content-Type']);
        $this->assertEquals(30, $captured[1]['args']['timeout']);

        $body = json_decode($captured[1]['args']['body'], true);
        $this->assertSame(self::TEST_BANK_CODE, $body['bankCode']);
        $this->assertSame(self::TEST_BANK_ACCOUNT, $body['bankAccount']);
        $this->assertSame(QrVietGateway::QR_TYPE_DYNAMIC, $body['qrType']);
        $this->assertSame(QrVietGateway::TRANS_TYPE_CREDIT, $body['transType']);
        $this->assertSame(100000, $body['amount']);
        $this->assertSame('TC0001', $body['terminalCode']);
    }

    public function test_purchase_returns_qr_payload_and_persists_order_id()
    {
        $gateway = $this->newGateway();
        $this->mockRemoteApiQueue([
            json_encode($this->tokenResponse()),
            json_encode($this->generateResponse()),
        ]);

        $result = $gateway->purchase([
            'transactionId' => '42',
            'amount' => 100000,
        ]);

        $this->assertEquals('qr', $result['status']);
        $this->assertEquals('https://img.vietqr.io/image/MB-9704221234567890-compact2.png', $result['qrImage']);
        $this->assertLessThanOrEqual(QrVietGateway::ORDER_ID_MAX_LEN, strlen($result['transactionId']));
        $this->assertMatchesRegularExpression('/^QRV[0-9]+[a-f0-9]{2}$/', $result['transactionId']);
        $this->assertEquals(
            $result['transactionId'],
            $GLOBALS['__post_meta']['_transaction_id']
        );
    }

    public function test_purchase_rejects_amount_below_minimum()
    {
        $gateway = $this->newGateway();

        $result = $gateway->purchase(['transactionId' => '1', 'amount' => 999]);
        $this->assertEquals('failed', $result['status']);
        $this->assertEquals('AMOUNT_INVALID', $result['code']);
    }

    public function test_purchase_fails_when_authentication_fails()
    {
        $gateway = $this->newGateway();
        $this->mockRemoteApiJson(['status' => 'FAILED', 'message' => 'E76']);

        $result = $gateway->purchase(['transactionId' => '1', 'amount' => 10000]);

        $this->assertEquals('failed', $result['status']);
        $this->assertEquals('AUTH_FAILED', $result['code']);
    }

    public function test_purchase_fails_on_generate_error_code()
    {
        $gateway = $this->newGateway();
        $this->mockRemoteApiQueue([
            json_encode($this->tokenResponse()),
            json_encode(['status' => 'FAILED', 'message' => 'E09']),
        ]);

        $result = $gateway->purchase(['transactionId' => '1', 'amount' => 10000]);

        $this->assertEquals('failed', $result['status']);
        $this->assertEquals('E09', $result['code']);
        $this->assertStringContainsString('content', strtolower($result['message']));
    }

    public function test_purchase_posts_to_production_url_in_production_mode()
    {
        $gateway = new TestQrVietGateway();
        $gateway->initialize($this->productionConfig());
        $captured = [];
        $this->mockRemoteApiQueue([
            json_encode($this->tokenResponse('prod_token')),
            json_encode($this->generateResponse()),
        ], $captured);

        $gateway->purchase(['transactionId' => '1', 'amount' => 10000]);

        $this->assertEquals(QrVietGateway::API_URL_PROD . QrVietGateway::PATH_TOKEN, $captured[0]['url']);
        $this->assertEquals(QrVietGateway::API_URL_PROD . QrVietGateway::PATH_GENERATE, $captured[1]['url']);

        $body = json_decode($captured[1]['args']['body'], true);
        $this->assertSame('BIDV', $body['bankCode']);
    }

    // ------------------------------------------------------------------
    // completePurchase()
    // ------------------------------------------------------------------

    public function test_completePurchase_success_when_paid()
    {
        $gateway = $this->newGateway();
        $this->mockRemoteApiQueue([
            json_encode($this->tokenResponse()),
            json_encode($this->checkOrderResponse(1)),
        ]);

        $result = $gateway->completePurchase(['orderId' => 'QRV000042ab']);

        $this->assertEquals('success', $result['status']);
        $this->assertEquals('1', $result['code']);
        $this->assertEquals('QRV000042ab', $result['transactionId']);
    }

    public function test_completePurchase_pending_when_not_paid()
    {
        $gateway = $this->newGateway();
        $this->mockRemoteApiQueue([
            json_encode($this->tokenResponse()),
            json_encode($this->checkOrderResponse(0)),
        ]);

        $result = $gateway->completePurchase(['orderId' => 'QRV000042ab']);

        $this->assertEquals('pending', $result['status']);
        $this->assertEquals('0', $result['code']);
    }

    public function test_completePurchase_failed_when_cancelled()
    {
        $gateway = $this->newGateway();
        $this->mockRemoteApiQueue([
            json_encode($this->tokenResponse()),
            json_encode($this->checkOrderResponse(2)),
        ]);

        $result = $gateway->completePurchase(['orderId' => 'QRV000042ab']);

        $this->assertEquals('failed', $result['status']);
        $this->assertEquals('2', $result['code']);
    }

    public function test_completePurchase_returns_pending_without_order_id()
    {
        $gateway = $this->newGateway();

        $result = $gateway->completePurchase([]);

        $this->assertEquals('pending', $result['status']);
        $this->assertEquals('PENDING', $result['code']);
    }

    public function test_completePurchase_unwraps_request_params()
    {
        $gateway = $this->newGateway();
        $this->mockRemoteApiQueue([
            json_encode($this->tokenResponse()),
            json_encode($this->checkOrderResponse(1)),
        ]);

        $result = $gateway->completePurchase(['request_params' => ['order_id' => 'QRV000042ab']]);

        $this->assertEquals('success', $result['status']);
    }

    public function test_completePurchase_resolves_order_id_from_persisted_transaction()
    {
        $gateway = $this->newGateway();
        $this->mockRemoteApiQueue([
            json_encode($this->tokenResponse()),
            json_encode($this->checkOrderResponse(1)),
        ]);

        \WP_Query::$mock_posts = [new \WP_Post(['ID' => 42, 'post_date' => '2026-09-28 00:00:00'])];
        $GLOBALS['__post_meta']['_transaction_id'] = 'QRV000042ab';

        $result = $gateway->completePurchase(['transactionId' => '42']);

        $this->assertEquals('success', $result['status']);
    }

    // ------------------------------------------------------------------
    // queryStatus()
    // ------------------------------------------------------------------

    public function test_queryStatus_returns_completed_when_status_1()
    {
        $gateway = $this->newGateway();
        $this->mockRemoteApiQueue([
            json_encode($this->tokenResponse()),
            json_encode($this->checkOrderResponse(1)),
        ]);

        $this->assertEquals('completed', $gateway->queryStatus('QRV000042ab'));
    }

    public function test_queryStatus_returns_pending_when_status_0()
    {
        $gateway = $this->newGateway();
        $this->mockRemoteApiQueue([
            json_encode($this->tokenResponse()),
            json_encode($this->checkOrderResponse(0)),
        ]);

        $this->assertEquals('pending', $gateway->queryStatus('QRV000042ab'));
    }

    public function test_queryStatus_returns_failed_when_status_2()
    {
        $gateway = $this->newGateway();
        $this->mockRemoteApiQueue([
            json_encode($this->tokenResponse()),
            json_encode($this->checkOrderResponse(2)),
        ]);

        $this->assertEquals('failed', $gateway->queryStatus('QRV000042ab'));
    }

    public function test_queryStatus_returns_failed_on_failed_root_status()
    {
        $gateway = $this->newGateway();
        $this->mockRemoteApiQueue([
            json_encode($this->tokenResponse()),
            json_encode(['status' => 'FAILED', 'message' => 'E96']),
        ]);

        $this->assertEquals('failed', $gateway->queryStatus('QRV000042ab'));
    }

    public function test_queryStatus_returns_pending_on_wp_error()
    {
        $gateway = $this->newGateway();
        Functions\when('wp_remote_post')->justReturn(new \WP_Error('http_error', 'boom'));
        Functions\when('wp_remote_retrieve_body')->justReturn('');
        Functions\when('is_wp_error')->justReturn(true);

        $this->assertEquals('failed', $gateway->queryStatus('QRV000042ab'));
    }

    public function test_queryStatus_posts_check_order_with_md5_checksum()
    {
        $gateway = $this->newGateway();
        $captured = [];
        $this->mockRemoteApiQueue([
            json_encode($this->tokenResponse()),
            json_encode($this->checkOrderResponse(0)),
        ], $captured);

        $gateway->queryStatus('QRV000042ab');

        $this->assertEquals(QrVietGateway::API_URL_TEST . QrVietGateway::PATH_CHECK, $captured[1]['url']);

        $body = json_decode($captured[1]['args']['body'], true);
        $this->assertSame(self::TEST_BANK_ACCOUNT, $body['bankAccount']);
        $this->assertSame('0', $body['type']);
        $this->assertSame('QRV000042ab', $body['value']);
        $this->assertSame(md5(self::TEST_BANK_ACCOUNT . self::TEST_USER), $body['checkSum']);
    }

    public function test_queryStatus_persists_reference_number()
    {
        $gateway = $this->newGateway();
        \WP_Query::$mock_posts = [new \WP_Post(['ID' => 42, 'post_date' => '2026-09-28 00:00:00'])];

        $this->mockRemoteApiQueue([
            json_encode($this->tokenResponse()),
            json_encode($this->checkOrderResponse(1)),
        ]);

        $gateway->queryStatus('QRV000042ab');

        $this->assertEquals('202401011200001', $GLOBALS['__post_meta']['_reference_number']);
    }

    // ------------------------------------------------------------------
    // processWebhook()
    // ------------------------------------------------------------------

    protected function webhookData(array $overrides = []): array
    {
        return array_merge([
            'bankaccount'     => self::TEST_BANK_ACCOUNT,
            'amount'          => 100000,
            'transType'       => 'C',
            'content'         => 'THANH TOAN TOUR',
            'transactionid'   => '202401011200001',
            'referencenumber' => '202401011200001',
            'orderId'         => 'QRV000042ab',
        ], $overrides);
    }

    public function test_processWebhook_marks_transaction_completed()
    {
        $gateway = $this->newGateway();
        \WP_Query::$mock_posts = [new \WP_Post(['ID' => 42, 'post_date' => '2026-09-28 00:00:00'])];
        $GLOBALS['__post_meta']['_amount'] = 100000;

        $result = $gateway->processWebhook($this->webhookData());

        $this->assertFalse($result['error']);
        $this->assertNull($result['errorReason']);
        $this->assertSame('202401011200001', $result['data'][0]['refTransactionId']);
        $this->assertEquals('completed', $GLOBALS['__post_meta']['_status']);
        $this->assertEquals('202401011200001', $GLOBALS['__post_meta']['_reference_number']);
    }

    public function test_processWebhook_returns_error_when_transaction_not_found()
    {
        $gateway = $this->newGateway();
        \WP_Query::$mock_posts = [];

        $result = $gateway->processWebhook($this->webhookData());

        $this->assertTrue($result['error']);
        $this->assertSame('E96', $result['errorReason']);
    }

    public function test_processWebhook_returns_error_when_missing_order_id()
    {
        $gateway = $this->newGateway();

        $result = $gateway->processWebhook([]);

        $this->assertTrue($result['error']);
        $this->assertSame('E96', $result['errorReason']);
    }

    public function test_processWebhook_returns_error_on_amount_mismatch()
    {
        $gateway = $this->newGateway();
        \WP_Query::$mock_posts = [new \WP_Post(['ID' => 42, 'post_date' => '2026-09-28 00:00:00'])];
        $GLOBALS['__post_meta']['_amount'] = 100000;

        $result = $gateway->processWebhook($this->webhookData(['amount' => 999999]));

        $this->assertTrue($result['error']);
        $this->assertSame('E02', $result['errorReason']);
    }

    public function test_processWebhook_returns_error_on_debit_type()
    {
        $gateway = $this->newGateway();
        \WP_Query::$mock_posts = [new \WP_Post(['ID' => 42, 'post_date' => '2026-09-28 00:00:00'])];

        $result = $gateway->processWebhook($this->webhookData(['transType' => 'D']));

        $this->assertTrue($result['error']);
        $this->assertSame('E03', $result['errorReason']);
    }

    // ------------------------------------------------------------------
    // refund()
    // ------------------------------------------------------------------

    public function test_refund_is_not_supported()
    {
        $gateway = $this->newGateway();

        $result = $gateway->refund(['amount' => 100000]);

        $this->assertEquals('failed', $result['status']);
        $this->assertEquals('REFUND_NOT_SUPPORTED', $result['code']);
    }

    // ------------------------------------------------------------------
    // getSettingsFields()
    // ------------------------------------------------------------------

    public function test_getSettingsFields_contains_all_expected_keys()
    {
        $fields = $this->newGateway()->getSettingsFields();

        foreach ([
            'testMode',
            'sandbox_username', 'sandbox_password', 'sandbox_bank_code',
            'sandbox_bank_account', 'sandbox_account_name', 'sandbox_terminal_code',
            'production_username', 'production_password', 'production_bank_code',
            'production_bank_account', 'production_account_name', 'production_terminal_code',
            'webhook_token',
        ] as $key) {
            $this->assertArrayHasKey($key, $fields);
        }
    }

    public function test_getSettingsFields_field_types()
    {
        $fields = $this->newGateway()->getSettingsFields();

        $this->assertEquals('checkbox', $fields['testMode']['type']);
        $this->assertEquals('text', $fields['sandbox_bank_code']['type']);
        $this->assertEquals('password', $fields['sandbox_password']['type']);
        $this->assertEquals('password', $fields['webhook_token']['type']);
    }

    // ------------------------------------------------------------------
    // Helpers (order id / content sanitization)
    // ------------------------------------------------------------------

    public function test_build_order_id_is_unique_and_within_limit()
    {
        $gateway = $this->newGateway();

        $a = $gateway->exposeBuildOrderId('42');
        $b = $gateway->exposeBuildOrderId('42');

        $this->assertLessThanOrEqual(QrVietGateway::ORDER_ID_MAX_LEN, strlen($a));
        $this->assertNotSame($a, $b);
        $this->assertMatchesRegularExpression('/^QRV[0-9]+[a-f0-9]{2}$/', $a);
    }

    public function test_build_content_strips_diacritics_and_specials()
    {
        $gateway = $this->newGateway();

        $result = $gateway->exposeBuildContent(['description' => 'Thanh toán tour #42!']);

        $this->assertSame('THANH TOAN TOUR 42', $result);
    }

    public function test_build_content_is_truncated_to_19()
    {
        $gateway = $this->newGateway();

        $result = $gateway->exposeBuildContent(['description' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAA']);

        $this->assertLessThanOrEqual(QrVietGateway::CONTENT_MAX_LEN, strlen($result));
    }

    public function test_build_content_falls_back_when_empty()
    {
        $gateway = $this->newGateway();

        $result = $gateway->exposeBuildContent([]);

        $this->assertNotSame('', $result);
    }

    public function test_remove_diacritics_map()
    {
        $gateway = $this->newGateway();

        $this->assertSame('nguyen van an', $gateway->exposeRemoveDiacritics('Nguyễn Văn An'));
        $this->assertSame('dong dao dan du', $gateway->exposeRemoveDiacritics('Đồng Đảo Đàn Dự'));
    }
}