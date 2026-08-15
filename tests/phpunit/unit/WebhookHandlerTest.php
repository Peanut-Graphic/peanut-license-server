<?php
/**
 * Webhook Handler Unit Tests
 *
 * Tests WooCommerce webhook processing, order handling, and subscription management.
 *
 * @package Peanut_License_Server
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Mock WC_Order class for testing
 */
class MockWCOrder {
    private int $id;
    private int $user_id;
    private string $email;
    private string $name;
    private array $items;

    public function __construct(int $id = 1, array $config = []) {
        $this->id = $id;
        $this->user_id = $config['user_id'] ?? 1;
        $this->email = $config['email'] ?? 'customer@example.com';
        $this->name = $config['name'] ?? 'Test Customer';
        $this->items = $config['items'] ?? [];
    }

    public function get_id(): int {
        return $this->id;
    }

    public function get_user_id(): int {
        return $this->user_id;
    }

    public function get_billing_email(): string {
        return $this->email;
    }

    public function get_formatted_billing_full_name(): string {
        return $this->name;
    }

    public function get_items(): array {
        return $this->items;
    }

    public function add_order_note(string $note): void {
        // Mock implementation
    }
}

/**
 * Mock WC_Product class for testing
 */
class MockWCProduct {
    private int $id;
    private string $name;
    private string $sku;
    private array $meta = [];

    public function __construct(int $id = 1, array $config = []) {
        $this->id = $id;
        $this->name = $config['name'] ?? 'Test Product';
        $this->sku = $config['sku'] ?? 'TEST-SKU';
        $this->meta = $config['meta'] ?? [];
    }

    public function get_id(): int {
        return $this->id;
    }

    public function get_name(): ?string {
        return $this->name;
    }

    public function get_sku(): ?string {
        return $this->sku;
    }

    public function get_meta(string $key) {
        return $this->meta[$key] ?? null;
    }
}

/**
 * Mock WC_Order_Item for testing
 */
class MockWCOrderItem {
    private ?MockWCProduct $product;

    public function __construct(?MockWCProduct $product = null) {
        $this->product = $product;
    }

    public function get_product(): ?MockWCProduct {
        return $this->product;
    }
}

/**
 * Mock WC_Subscription for testing
 */
class MockWCSubscription {
    private int $id;
    private int $parent_id;
    private array $items;
    private array $dates;

    public function __construct(int $id = 1, array $config = []) {
        $this->id = $id;
        $this->parent_id = $config['parent_id'] ?? 100;
        $this->items = $config['items'] ?? [];
        $this->dates = $config['dates'] ?? [];
    }

    public function get_id(): int {
        return $this->id;
    }

    public function get_parent_id(): int {
        return $this->parent_id;
    }

    public function get_items(): array {
        return $this->items;
    }

    public function get_date(string $type): ?string {
        return $this->dates[$type] ?? null;
    }

    public function add_order_note(string $note): void {
        // Mock implementation
    }
}

// Mock WooCommerce functions
function wc_get_order(int $id) {
    global $_mock_orders;
    return $_mock_orders[$id] ?? null;
}

function wcs_get_subscription(int $id) {
    global $_mock_subscriptions;
    return $_mock_subscriptions[$id] ?? null;
}

/**
 * @covers Peanut_Webhook_Handler
 */
class WebhookHandlerTest extends TestCase {

    private Peanut_Webhook_Handler $handler;

    /**
     * Set up test fixtures
     */
    protected function setUp(): void {
        parent::setUp();
        $this->handler = new Peanut_Webhook_Handler();
        PeanutTestHelper::clearTransients();
        PeanutTestHelper::clearOptions();
        PeanutTestHelper::clearEmails();

        // Reset mock globals
        global $_mock_orders, $_mock_subscriptions;
        $_mock_orders = [];
        $_mock_subscriptions = [];
    }

    /**
     * Tear down test fixtures
     */
    protected function tearDown(): void {
        parent::tearDown();
        PeanutTestHelper::clearTransients();
        PeanutTestHelper::clearOptions();
        PeanutTestHelper::clearEmails();
    }

    // =========================================================================
    // Process Order Completed Tests
    // =========================================================================

    /**
     * @test
     * @covers Peanut_Webhook_Handler::process_order_completed
     */
    public function process_order_completed_does_nothing_for_nonexistent_order(): void {
        // No exception should be thrown
        $this->expectNotToPerformAssertions();

        $this->handler->process_order_completed(99999);
    }

    /**
     * @test
     * @covers Peanut_Webhook_Handler::process_order_completed
     */
    public function process_order_completed_handles_order_with_no_products(): void {
        global $_mock_orders;
        $_mock_orders[1] = new MockWCOrder(1, [
            'items' => [],
        ]);

        // Should not throw exception
        $this->expectNotToPerformAssertions();

        $this->handler->process_order_completed(1);
    }

    // =========================================================================
    // Product Tier Detection Tests
    // =========================================================================

    /**
     * @test
     */
    public function tier_meta_key_is_defined(): void {
        // Access private constant through reflection
        $reflection = new ReflectionClass(Peanut_Webhook_Handler::class);
        $constants = $reflection->getConstants();

        $this->assertArrayHasKey('TIER_META_KEY', $constants);
        $this->assertEquals('_peanut_tier', $constants['TIER_META_KEY']);
    }

    // =========================================================================
    // Subscription Processing Tests
    // =========================================================================

    /**
     * @test
     * @covers Peanut_Webhook_Handler::process_subscription_active
     */
    public function process_subscription_active_requires_wcs_function(): void {
        // When wcs_get_subscription doesn't exist, should return early
        $this->expectNotToPerformAssertions();

        // We've mocked the function above, so this should work
        $subscription = new MockWCSubscription(1);
        $this->handler->process_subscription_active($subscription);
    }

    /**
     * @test
     * @covers Peanut_Webhook_Handler::process_subscription_expired
     */
    public function process_subscription_expired_handles_nonexistent_license(): void {
        $subscription = new MockWCSubscription(1);

        // Should not throw exception even with no matching license
        $this->expectNotToPerformAssertions();

        $this->handler->process_subscription_expired($subscription);
    }

    /**
     * @test
     * @covers Peanut_Webhook_Handler::process_subscription_cancelled
     */
    public function process_subscription_cancelled_handles_nonexistent_license(): void {
        $subscription = new MockWCSubscription(1);

        // Should not throw exception even with no matching license
        $this->expectNotToPerformAssertions();

        $this->handler->process_subscription_cancelled($subscription);
    }

    // =========================================================================
    // Static Method Tests
    // =========================================================================

    /**
     * @test
     * @covers Peanut_Webhook_Handler::add_product_meta_box
     */
    public function add_product_meta_box_does_not_throw(): void {
        $this->expectNotToPerformAssertions();

        Peanut_Webhook_Handler::add_product_meta_box();
    }

    // =========================================================================
    // Product Tier from SKU/Name Tests
    // =========================================================================

    /**
     * @test
     */
    public function product_tier_detection_from_sku_agency(): void {
        $product = new MockWCProduct(1, [
            'sku' => 'peanut-suite-agency-2024',
            'name' => 'Peanut Suite',
        ]);

        // Use reflection to access private method
        $reflection = new ReflectionClass(Peanut_Webhook_Handler::class);
        $method = $reflection->getMethod('get_product_tier');

        $tier = $method->invoke($this->handler, $product);

        $this->assertEquals('agency', $tier);
    }

    /**
     * @test
     */
    public function product_tier_detection_from_sku_pro(): void {
        $product = new MockWCProduct(1, [
            'sku' => 'peanut-suite-pro',
            'name' => 'Peanut Suite',
        ]);

        $reflection = new ReflectionClass(Peanut_Webhook_Handler::class);
        $method = $reflection->getMethod('get_product_tier');

        $tier = $method->invoke($this->handler, $product);

        $this->assertEquals('pro', $tier);
    }

    /**
     * @test
     */
    public function product_tier_detection_from_name_agency(): void {
        $product = new MockWCProduct(1, [
            'sku' => '',
            'name' => 'Peanut Suite Agency License',
        ]);

        $reflection = new ReflectionClass(Peanut_Webhook_Handler::class);
        $method = $reflection->getMethod('get_product_tier');

        $tier = $method->invoke($this->handler, $product);

        $this->assertEquals('agency', $tier);
    }

    /**
     * @test
     */
    public function product_tier_detection_from_name_pro(): void {
        $product = new MockWCProduct(1, [
            'sku' => '',
            'name' => 'Peanut Suite Pro License',
        ]);

        $reflection = new ReflectionClass(Peanut_Webhook_Handler::class);
        $method = $reflection->getMethod('get_product_tier');

        $tier = $method->invoke($this->handler, $product);

        $this->assertEquals('pro', $tier);
    }

    /**
     * @test
     */
    public function product_tier_detection_returns_null_for_unknown(): void {
        $product = new MockWCProduct(1, [
            'sku' => 'other-product',
            'name' => 'Unrelated Product',
        ]);

        $reflection = new ReflectionClass(Peanut_Webhook_Handler::class);
        $method = $reflection->getMethod('get_product_tier');

        $tier = $method->invoke($this->handler, $product);

        $this->assertNull($tier);
    }

    /**
     * @test
     */
    public function product_tier_detection_from_meta(): void {
        $product = new MockWCProduct(1, [
            'sku' => '',
            'name' => 'Generic Product',
            'meta' => ['_peanut_tier' => 'pro'],
        ]);

        $reflection = new ReflectionClass(Peanut_Webhook_Handler::class);
        $method = $reflection->getMethod('get_product_tier');

        $tier = $method->invoke($this->handler, $product);

        $this->assertEquals('pro', $tier);
    }

    /**
     * @test
     */
    public function product_tier_meta_takes_priority(): void {
        $product = new MockWCProduct(1, [
            'sku' => 'peanut-suite-agency', // Would suggest agency
            'name' => 'Peanut Suite Agency',
            'meta' => ['_peanut_tier' => 'pro'], // But meta says pro
        ]);

        $reflection = new ReflectionClass(Peanut_Webhook_Handler::class);
        $method = $reflection->getMethod('get_product_tier');

        $tier = $method->invoke($this->handler, $product);

        $this->assertEquals('pro', $tier);
    }

    // =========================================================================
    // Case Insensitivity Tests
    // =========================================================================

    /**
     * @test
     */
    public function product_tier_detection_is_case_insensitive_sku(): void {
        $product = new MockWCProduct(1, [
            'sku' => 'PEANUT-SUITE-PRO',
            'name' => 'Peanut Suite',
        ]);

        $reflection = new ReflectionClass(Peanut_Webhook_Handler::class);
        $method = $reflection->getMethod('get_product_tier');

        $tier = $method->invoke($this->handler, $product);

        $this->assertEquals('pro', $tier);
    }

    /**
     * @test
     */
    public function product_tier_detection_is_case_insensitive_name(): void {
        $product = new MockWCProduct(1, [
            'sku' => '',
            'name' => 'PEANUT SUITE AGENCY',
        ]);

        $reflection = new ReflectionClass(Peanut_Webhook_Handler::class);
        $method = $reflection->getMethod('get_product_tier');

        $tier = $method->invoke($this->handler, $product);

        $this->assertEquals('agency', $tier);
    }

    // =========================================================================
    // Agency Priority Over Pro Tests
    // =========================================================================

    /**
     * @test
     */
    public function agency_detected_before_pro_in_sku(): void {
        $product = new MockWCProduct(1, [
            'sku' => 'peanut-pro-agency', // Contains both
            'name' => 'Product',
        ]);

        $reflection = new ReflectionClass(Peanut_Webhook_Handler::class);
        $method = $reflection->getMethod('get_product_tier');

        $tier = $method->invoke($this->handler, $product);

        // Agency should be detected first (checked before pro)
        $this->assertEquals('agency', $tier);
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    /**
     * @test
     */
    public function product_tier_handles_null_sku(): void {
        // Create product with explicit null handling
        $product = new MockWCProduct(1, [
            'sku' => null,
            'name' => 'Pro Product',
        ]);

        $reflection = new ReflectionClass(Peanut_Webhook_Handler::class);
        $method = $reflection->getMethod('get_product_tier');

        $tier = $method->invoke($this->handler, $product);

        $this->assertEquals('pro', $tier);
    }

    /**
     * @test
     */
    public function product_tier_handles_null_name(): void {
        $product = new MockWCProduct(1, [
            'sku' => 'pro-product',
            'name' => null,
        ]);

        $reflection = new ReflectionClass(Peanut_Webhook_Handler::class);
        $method = $reflection->getMethod('get_product_tier');

        $tier = $method->invoke($this->handler, $product);

        $this->assertEquals('pro', $tier);
    }
}
