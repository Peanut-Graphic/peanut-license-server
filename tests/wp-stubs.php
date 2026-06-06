<?php
/**
 * Minimal WordPress class/function stubs for the unit test suite.
 *
 * These are NOT full WordPress implementations — just enough surface for the
 * pure-logic classes under test (e.g. Peanut_API_Security) to be exercised
 * without standing up the full wp-phpunit harness. Behavioural WordPress
 * functions are stubbed separately via Brain Monkey in bootstrap.php.
 *
 * @package Peanut_License_Server\Tests
 */

if (!class_exists('WP_Error')) {
    /**
     * Minimal WP_Error stub mirroring the subset of the API the code uses.
     */
    class WP_Error {
        /** @var string */
        protected $code;
        /** @var string */
        protected $message;
        /** @var mixed */
        protected $data;

        public function __construct($code = '', $message = '', $data = '') {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }

        public function get_error_code() {
            return $this->code;
        }

        public function get_error_message() {
            return $this->message;
        }

        public function get_error_data() {
            return $this->data;
        }
    }
}

if (!function_exists('is_wp_error')) {
    /**
     * @param mixed $thing
     */
    function is_wp_error($thing): bool {
        return $thing instanceof WP_Error;
    }
}

if (!class_exists('WP_REST_Request')) {
    /**
     * Minimal WP_REST_Request stub.
     *
     * Exists so production type hints (WP_REST_Request $request) resolve and so
     * test doubles can extend it. Method signatures match the test doubles in
     * the suite so subclasses are valid LSP overrides.
     */
    class WP_REST_Request {
        public function __construct($method = '', $route = '', $attributes = []) {
        }

        public function get_param(string $key) {
            return null;
        }

        public function get_header(string $key): ?string {
            return null;
        }

        public function get_body(): string {
            return '';
        }

        public function get_route(): string {
            return '';
        }
    }
}
