<?php
/**
 * PHPStan stubs for WordPress, WooCommerce, and Action Scheduler symbols.
 *
 * These declarations are intentionally narrow: they cover only runtime symbols
 * referenced by first-party plugin code while the full WordPress/WooCommerce
 * stack is absent from the local PHPStan process.
 *
 * @package AdditionalSubscriptionsAnalytics
 */

/**
 * WordPress database adapter.
 */
class wpdb {

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	public string $prefix;

	/**
	 * Prepare a SQL query.
	 *
	 * @param string $query SQL query with placeholders.
	 * @param mixed  ...$args Placeholder values.
	 *
	 * @return string
	 */
	public function prepare( string $query, mixed ...$args ): string {}

	/**
	 * Run a SQL query.
	 *
	 * @param string $query SQL query.
	 *
	 * @return int|bool
	 */
	public function query( string $query ): int|bool {}

	/**
	 * Insert a row.
	 *
	 * @param string            $table  Table name.
	 * @param array<string,mixed> $data   Row data.
	 * @param array<int,string> $format Value formats.
	 *
	 * @return int|bool
	 */
	public function insert( string $table, array $data, array $format = array() ): int|bool {}

	/**
	 * Replace a row.
	 *
	 * @param string            $table  Table name.
	 * @param array<string,mixed> $data   Row data.
	 * @param array<int,string> $format Value formats.
	 *
	 * @return int|bool
	 */
	public function replace( string $table, array $data, array $format = array() ): int|bool {}

	/**
	 * Delete rows.
	 *
	 * @param string            $table Table name.
	 * @param array<string,mixed> $where Where values.
	 * @param array<int,string> $where_format Where value formats.
	 *
	 * @return int|bool
	 */
	public function delete( string $table, array $where, array $where_format = array() ): int|bool {}

	/**
	 * Get one value.
	 *
	 * @param string $query SQL query.
	 *
	 * @return string|null
	 */
	public function get_var( string $query ): ?string {}

	/**
	 * Get one row.
	 *
	 * @param string $query  SQL query.
	 * @param mixed  $output Optional output format.
	 *
	 * @return object|array<string,mixed>|null
	 */
	public function get_row( string $query, mixed $output = null ): object|array|null {}

	/**
	 * Get result rows.
	 *
	 * @param string $query  SQL query.
	 * @param mixed  $output Optional output format.
	 *
	 * @return array<int,object|array<string,mixed>>
	 */
	public function get_results( string $query, mixed $output = null ): array {}

	/**
	 * Get one column.
	 *
	 * @param string $query SQL query.
	 *
	 * @return array<int,mixed>
	 */
	public function get_col( string $query ): array {}

	/**
	 * Get database charset/collation.
	 *
	 * @return string
	 */
	public function get_charset_collate(): string {}
}

/**
 * Global WordPress database object.
 *
 * @var wpdb $wpdb
 */
$wpdb = new wpdb();

/**
 * Register a callback on a hook.
 *
 * @param string   $hook_name     Hook name.
 * @param callable $callback      Callback.
 * @param int      $priority      Priority.
 * @param int      $accepted_args Accepted argument count.
 *
 * @return true
 */
function add_action( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): true {}

/**
 * Register a callback on a filter hook.
 *
 * @param string   $hook_name     Hook name.
 * @param callable $callback      Callback.
 * @param int      $priority      Priority.
 * @param int      $accepted_args Accepted argument count.
 *
 * @return true
 */
function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): true {}

/**
 * Apply filters to a value.
 *
 * @param string $hook_name Hook name.
 * @param mixed  $value     Value to filter.
 * @param mixed  ...$args   Additional args.
 *
 * @return mixed
 */
function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed {}

/**
 * Translate text.
 *
 * @param string $text   Source text.
 * @param string $domain Text domain.
 *
 * @return string
 */
function __( string $text, string $domain = 'default' ): string {}

/**
 * Sanitize a text field.
 *
 * @param string $str Source string.
 *
 * @return string
 */
function sanitize_text_field( string $str ): string {}

/**
 * Escape HTML.
 *
 * @param string $text Source text.
 *
 * @return string
 */
function esc_html( string $text ): string {}

/**
 * Get a plugin filesystem path.
 *
 * @param string $file Plugin file.
 *
 * @return string
 */
function plugin_dir_path( string $file ): string {}

/**
 * Get a plugin URL.
 *
 * @param string $file Plugin file.
 *
 * @return string
 */
function plugin_dir_url( string $file ): string {}

/**
 * Get plugin basename.
 *
 * @param string $file Plugin file.
 *
 * @return string
 */
function plugin_basename( string $file ): string {}

/**
 * Register activation callback.
 *
 * @param string   $file     Plugin file.
 * @param callable $callback Callback.
 *
 * @return void
 */
function register_activation_hook( string $file, callable $callback ): void {}

/**
 * Register deactivation callback.
 *
 * @param string   $file     Plugin file.
 * @param callable $callback Callback.
 *
 * @return void
 */
function register_deactivation_hook( string $file, callable $callback ): void {}

/**
 * Update an option.
 *
 * @param string $option   Option name.
 * @param mixed  $value    Option value.
 * @param mixed  $autoload Autoload setting.
 *
 * @return bool
 */
function update_option( string $option, mixed $value, mixed $autoload = null ): bool {}

/**
 * Add an option.
 *
 * @param string $option     Option name.
 * @param mixed  $value      Option value.
 * @param string $deprecated Deprecated argument.
 * @param mixed  $autoload   Autoload setting.
 *
 * @return bool
 */
function add_option( string $option, mixed $value = '', string $deprecated = '', mixed $autoload = null ): bool {}

/**
 * Get an option.
 *
 * @param string $option  Option name.
 * @param mixed  $default Default value.
 *
 * @return mixed
 */
function get_option( string $option, mixed $default = false ): mixed {}

/**
 * Check a user capability.
 *
 * @param string $capability Capability name.
 * @param mixed  ...$args    Additional args.
 *
 * @return bool
 */
function current_user_can( string $capability, mixed ...$args ): bool {}

/**
 * Sanitize a key string.
 *
 * @param string $key Source key.
 *
 * @return string
 */
function sanitize_key( string $key ): string {}

/**
 * Delete an option.
 *
 * @param string $option Option name.
 *
 * @return bool
 */
function delete_option( string $option ): bool {}

/**
 * Load a plugin text domain.
 *
 * @param string $domain          Text domain.
 * @param bool   $deprecated      Deprecated argument.
 * @param string $plugin_rel_path Relative language path.
 *
 * @return bool
 */
function load_plugin_textdomain( string $domain, bool $deprecated = false, string $plugin_rel_path = '' ): bool {}

/**
 * Clear a scheduled WP-Cron hook.
 *
 * @param string $hook Hook name.
 * @param array<int,mixed> $args Hook arguments.
 *
 * @return int|false
 */
function wp_clear_scheduled_hook( string $hook, array $args = array() ): int|false {}

/**
 * Run database delta operations.
 *
 * @param string|array<int,string> $queries SQL query or queries.
 * @param bool                     $execute Whether to execute.
 *
 * @return array<int|string,string>
 */
function dbDelta( string|array $queries = '', bool $execute = true ): array {}

/**
 * Format a WooCommerce decimal.
 *
 * @param mixed $number Number.
 * @param mixed $dp     Decimal places.
 *
 * @return string
 */
function wc_format_decimal( mixed $number, mixed $dp = false ): string {}

/**
 * Get the WooCommerce site timezone string.
 *
 * @return string
 */
function wc_timezone_string(): string {}

/**
 * Get the store price decimal precision.
 *
 * @return int
 */
function wc_get_price_decimals(): int {}

/**
 * Get a WooCommerce product.
 *
 * @param int $product_id Product ID.
 *
 * @return object|false
 */
function wc_get_product( int $product_id ): object|false {}

/**
 * Get a WooCommerce order.
 *
 * @param int $order_id Order ID.
 *
 * @return object|false
 */
function wc_get_order( int $order_id ): object|false {}

/**
 * Resolve an order item ID to an order ID.
 *
 * @param int $item_id Order item ID.
 *
 * @return int
 */
function wc_get_order_id_by_order_item_id( int $item_id ): int {}

/**
 * Get the REST authorization error status.
 *
 * @return int
 */
function rest_authorization_required_code(): int {}

/**
 * Build a REST URL.
 *
 * @param string $path REST path.
 *
 * @return string
 */
function rest_url( string $path = '' ): string {}

/**
 * Get the edit link for a post.
 *
 * @param int    $post_id Post ID.
 * @param string $context Link context.
 *
 * @return string|null
 */
function get_edit_post_link( int $post_id, string $context = 'display' ): ?string {}

/**
 * Get the permalink for a post.
 *
 * @param int $post_id Post ID.
 *
 * @return string|false
 */
function get_permalink( int $post_id ): string|false {}

/**
 * Unschedule Action Scheduler actions.
 *
 * @param string              $hook  Hook name.
 * @param array<string,mixed>|null $args  Action args.
 * @param string              $group Action group.
 *
 * @return void
 */
function as_unschedule_all_actions( string $hook, ?array $args = null, string $group = '' ): void {}

/**
 * Get next scheduled Action Scheduler timestamp.
 *
 * @param string              $hook  Hook name.
 * @param array<string,mixed> $args  Action args.
 * @param string              $group Action group.
 *
 * @return int|false
 */
function as_next_scheduled_action( string $hook, array $args = array(), string $group = '' ): int|false {}

/**
 * Enqueue an async Action Scheduler action.
 *
 * @param string              $hook   Hook name.
 * @param array<string,mixed> $args   Action args.
 * @param string              $group  Action group.
 * @param bool                $unique Whether action should be unique.
 *
 * @return int
 */
function as_enqueue_async_action( string $hook, array $args = array(), string $group = '', bool $unique = false ): int {}

/**
 * Schedule a single Action Scheduler action.
 *
 * @param int                 $timestamp Timestamp.
 * @param string              $hook      Hook name.
 * @param array<string,mixed> $args      Action args.
 * @param string              $group     Action group.
 * @param bool                $unique    Whether action should be unique.
 *
 * @return int
 */
function as_schedule_single_action(
	int $timestamp,
	string $hook,
	array $args = array(),
	string $group = '',
	bool $unique = false
): int {}

/**
 * WP-CLI facade.
 */
class WP_CLI {

	/**
	 * Register a command.
	 *
	 * @param string   $name     Command name.
	 * @param callable $callable Command callback.
	 *
	 * @return void
	 */
	public static function add_command( string $name, callable $callable ): void {}

	/**
	 * Emit success.
	 *
	 * @param string $message Message.
	 *
	 * @return void
	 */
	public static function success( string $message ): void {}

	/**
	 * Emit warning.
	 *
	 * @param string $message Message.
	 *
	 * @return void
	 */
	public static function warning( string $message ): void {}

	/**
	 * Emit error.
	 *
	 * @param string $message Message.
	 *
	 * @return void
	 */
	public static function error( string $message ): void {}
}
