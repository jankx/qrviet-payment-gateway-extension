<?php
namespace Jankx\Extensions\QrViet\Tests;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Jankx\Extensions\QrViet\QrVietPaymentGatewayExtension;
use Jankx\Extensions\QrViet\Gateways\QrVietGateway;
use Jankx\Extensions\PaymentSystem\Gateways\GatewayManager;

class QrVietPaymentGatewayExtensionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        qrviet_test_stub_wp_functions();

        $ref = new \ReflectionProperty(GatewayManager::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__options'], $GLOBALS['__post_meta']);
        $_SERVER = [];
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_get_instance_returns_singleton()
    {
        $instance = new QrVietPaymentGatewayExtension();
        $this->assertSame($instance, QrVietPaymentGatewayExtension::get_instance());
    }

    public function test_register_hooks_wires_all_hooks()
    {
        $extension = new QrVietPaymentGatewayExtension();

        $actions = [];
        $filters = [];
        Functions\when('add_action')->alias(function ($tag, $callback) use (&$actions) {
            $actions[] = ['tag' => $tag, 'callback' => $callback];
            return true;
        });
        Functions\when('add_filter')->alias(function ($tag, $callback) use (&$filters) {
            $filters[] = ['tag' => $tag, 'callback' => $callback];
            return true;
        });

        $extension->register_hooks();

        $this->assertContains(
            ['tag' => 'jankx/payment/register_gateways', 'callback' => [$extension, 'registerGateways']],
            $actions
        );
        $this->assertContains(
            ['tag' => 'admin_init', 'callback' => [$extension, 'registerGatewaySettings']],
            $actions
        );
        $this->assertContains(
            ['tag' => 'rest_api_init', 'callback' => [$extension, 'registerWebhookRoutes']],
            $actions
        );
        $this->assertContains(
            ['tag' => 'jankx/payment/gateway/qrviet/default_config', 'callback' => [$extension, 'defaultConfig']],
            $filters
        );
    }

    public function test_registerGateways_registers_qrviet_gateway()
    {
        GatewayManager::getInstance();
        $extension = new QrVietPaymentGatewayExtension();
        $extension->registerGateways();

        $manager = GatewayManager::getInstance();
        $this->assertTrue($manager->hasGateway('qrviet'));
    }

    public function test_registerGateways_registers_correct_class()
    {
        GatewayManager::getInstance();
        $extension = new QrVietPaymentGatewayExtension();
        $extension->registerGateways();

        $gateways = GatewayManager::getInstance()->getAll();
        $this->assertSame(QrVietGateway::class, $gateways['qrviet']);
    }

    public function test_default_config()
    {
        $extension = new QrVietPaymentGatewayExtension();
        $config = $extension->defaultConfig(['testMode' => false]);

        $this->assertEquals('1', $config['testMode']);
        $this->assertEquals('', $config['sandbox_username']);
        $this->assertEquals('', $config['sandbox_password']);
        $this->assertEquals('', $config['sandbox_bank_code']);
        $this->assertEquals('', $config['sandbox_bank_account']);
        $this->assertEquals('', $config['webhook_token']);
    }

    public function test_default_config_preserves_custom_keys()
    {
        $extension = new QrVietPaymentGatewayExtension();
        $config = $extension->defaultConfig([
            'testMode' => false,
            'custom_key' => 'custom-value',
        ]);

        $this->assertEquals('custom-value', $config['custom_key']);
    }

    public function test_sanitize_gateway_settings_strips_text_fields()
    {
        $extension = new QrVietPaymentGatewayExtension();
        $sanitized = $extension->sanitizeGatewaySettings([
            'sandbox_username'    => "  vietqr_user  ",
            'sandbox_bank_account'=> "  9704221234567890 \n",
        ]);

        $this->assertEquals('vietqr_user', $sanitized['sandbox_username']);
        $this->assertEquals('9704221234567890', $sanitized['sandbox_bank_account']);
    }

    public function test_sanitize_gateway_settings_handles_non_array()
    {
        $extension = new QrVietPaymentGatewayExtension();
        $this->assertEquals([], $extension->sanitizeGatewaySettings('not-an-array'));
    }

    public function test_register_gateway_settings_registers_qrviet_option()
    {
        $extension = new QrVietPaymentGatewayExtension();

        $captured = [];
        Functions\when('register_setting')->alias(function ($group, $name, $args) use (&$captured) {
            $captured[] = ['group' => $group, 'name' => $name, 'args' => $args];
            return true;
        });

        $extension->registerGatewaySettings();

        $this->assertCount(1, $captured);
        $this->assertEquals('jankx_payment', $captured[0]['group']);
        $this->assertEquals('jankx_payment_gateway_qrviet', $captured[0]['name']);
        $this->assertEquals('array', $captured[0]['args']['type']);
        $this->assertSame([$extension, 'sanitizeGatewaySettings'], $captured[0]['args']['sanitize_callback']);
    }

    // ------------------------------------------------------------------
    // REST webhook
    // ------------------------------------------------------------------

    public function test_registerWebhookRoutes_registers_transaction_sync_route()
    {
        $extension = new QrVietPaymentGatewayExtension();

        $captured = [];
        Functions\when('register_rest_route')->alias(function ($namespace, $route, $args) use (&$captured) {
            $captured[] = ['namespace' => $namespace, 'route' => $route, 'args' => $args];
            return true;
        });

        $extension->registerWebhookRoutes();

        $this->assertCount(1, $captured);
        $this->assertEquals('jankx/v1', $captured[0]['namespace']);
        $this->assertEquals(QrVietPaymentGatewayExtension::WEBHOOK_ROUTE, $captured[0]['route']);
        $this->assertEquals('POST', $captured[0]['args']['methods']);
        $this->assertSame([$extension, 'handleTransactionSync'], $captured[0]['args']['callback']);
        $this->assertSame([$extension, 'verifyWebhookPermission'], $captured[0]['args']['permission_callback']);
    }

    public function test_verifyWebhookPermission_accepts_matching_bearer_token()
    {
        $extension = new QrVietPaymentGatewayExtension();
        $GLOBALS['__options']['jankx_payment_gateway_qrviet'] = ['webhook_token' => 'secret-token'];
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret-token';

        $this->assertTrue($extension->verifyWebhookPermission());
    }

    public function test_verifyWebhookPermission_rejects_mismatched_token()
    {
        $extension = new QrVietPaymentGatewayExtension();
        $GLOBALS['__options']['jankx_payment_gateway_qrviet'] = ['webhook_token' => 'secret-token'];
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer wrong-token';

        $this->assertFalse($extension->verifyWebhookPermission());
    }

    public function test_verifyWebhookPermission_rejects_missing_header()
    {
        $extension = new QrVietPaymentGatewayExtension();
        $GLOBALS['__options']['jankx_payment_gateway_qrviet'] = ['webhook_token' => 'secret-token'];

        $this->assertFalse($extension->verifyWebhookPermission());
    }

    public function test_verifyWebhookPermission_rejects_when_token_not_configured()
    {
        $extension = new QrVietPaymentGatewayExtension();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer whatever';

        $this->assertFalse($extension->verifyWebhookPermission());
    }

    public function test_handleTransactionSync_marks_transaction_completed()
    {
        $extension = new QrVietPaymentGatewayExtension();
        $extension->registerGateways();
        $GLOBALS['__options']['jankx_payment_gateway_qrviet'] = [
            'testMode' => '1',
            'sandbox_username'      => 'vietqr_user',
            'sandbox_password'      => 'vietqr_pass',
            'sandbox_bank_code'     => 'MB',
            'sandbox_bank_account'  => '9704221234567890',
            'webhook_token'         => 'secret-token',
        ];

        \WP_Query::$mock_posts = [new \WP_Post(['ID' => 42, 'post_date' => '2026-09-28 00:00:00'])];
        $GLOBALS['__post_meta']['_amount'] = 100000;

        $request = new \WP_REST_Request('POST', QrVietPaymentGatewayExtension::WEBHOOK_ROUTE);
        foreach ([
            'bankaccount'     => '9704221234567890',
            'amount'          => 100000,
            'transType'       => 'C',
            'content'         => 'THANH TOAN TOUR',
            'referencenumber' => '202401011200001',
            'orderId'         => 'QRV000042ab',
        ] as $key => $value) {
            $request->set_param($key, $value);
        }

        $response = $extension->handleTransactionSync($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();
        $this->assertFalse($data['error']);
        $this->assertSame('202401011200001', $data['data'][0]['refTransactionId']);
        $this->assertEquals('completed', $GLOBALS['__post_meta']['_status']);
    }

    public function test_handleTransactionSync_returns_error_when_gateway_missing()
    {
        $extension = new QrVietPaymentGatewayExtension();

        $request = new \WP_REST_Request('POST', QrVietPaymentGatewayExtension::WEBHOOK_ROUTE);
        $response = $extension->handleTransactionSync($request);

        $this->assertSame(400, $response->get_status());
        $this->assertTrue($response->get_data()['error']);
        $this->assertSame('E76', $response->get_data()['errorReason']);
    }
}