<?php
/**
 * Lifecycle bootstrap regression tests.
 *
 * @package Peanut_License_Server
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LifecycleBootstrapTest extends TestCase {
    public function test_services_initialize_after_the_server_singleton_is_assigned(): void {
        $source = file_get_contents(dirname(__DIR__, 3) . '/peanut-license-server.php');
        $this->assertIsString($source);

        $singleton = strpos($source, '$server = Peanut_License_Server::get_instance();');
        $this->assertNotFalse($singleton);

        foreach ([
            'Peanut_License_DB_Migrations::init();',
            'Peanut_Subscription_Sync::init();',
            'Peanut_Affiliate_System::init();',
        ] as $initializer) {
            $position = strpos($source, $initializer);
            $this->assertNotFalse($position, $initializer . ' must be called by the root bootstrap');
            $this->assertGreaterThan($singleton, $position, $initializer . ' must run after singleton assignment');
        }
    }

    public function test_services_do_not_register_callbacks_on_finished_plugins_loaded(): void {
        foreach ([
            'includes/class-db-migrations.php',
            'includes/class-subscription-sync.php',
            'includes/class-affiliate-system.php',
        ] as $relative) {
            $source = file_get_contents(dirname(__DIR__, 3) . '/' . $relative);
            $this->assertIsString($source);
            $this->assertStringNotContainsString("add_action('plugins_loaded'", $source, $relative);
        }
    }
}
