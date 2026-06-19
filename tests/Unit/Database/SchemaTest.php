<?php
/**
 * Tests for database schema definitions.
 *
 * @package AdditionalSubscriptionsAnalytics\Tests
 */

namespace AdditionalSubscriptionsAnalytics\Tests\Unit\Database;

use AdditionalSubscriptionsAnalytics\Database\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Tests database schema definitions.
 *
 * @covers \AdditionalSubscriptionsAnalytics\Database\Schema
 */
final class SchemaTest extends TestCase {

	/**
	 * Test table names include the supplied prefix.
	 *
	 * @return void
	 */
	public function test_get_table_names_uses_prefix(): void {
		$tables = Schema::get_table_names( 'wp_' );

		$this->assertSame( 'wp_wc_subscriptions_stats', $tables['subscriptions_stats'] );
		$this->assertSame( 'wp_wc_subscription_product_lookup', $tables['subscription_product_lookup'] );
	}

	/**
	 * Test stats table SQL contains required columns and indexes.
	 *
	 * @return void
	 */
	public function test_stats_schema_contains_required_columns_and_indexes(): void {
		$sql = Schema::get_table_schema_sql( 'wp_', 'DEFAULT CHARACTER SET utf8mb4' )['subscriptions_stats'];

		$this->assertStringContainsString( 'CREATE TABLE wp_wc_subscriptions_stats', $sql );
		$this->assertStringContainsString( 'subscription_id bigint(20) unsigned NOT NULL', $sql );
		$this->assertStringContainsString( 'next_payment_date_gmt datetime DEFAULT NULL', $sql );
		$this->assertStringContainsString( 'recurring_total decimal(26,8) NOT NULL DEFAULT 0', $sql );
		$this->assertStringContainsString( 'PRIMARY KEY  (subscription_id)', $sql );
		$this->assertStringContainsString( 'KEY status_next_payment (status,next_payment_date_gmt,subscription_id)', $sql );
	}

	/**
	 * Test product lookup SQL contains required columns and indexes.
	 *
	 * @return void
	 */
	public function test_product_lookup_schema_contains_required_columns_and_indexes(): void {
		$sql = Schema::get_table_schema_sql( 'wp_', 'DEFAULT CHARACTER SET utf8mb4' )['subscription_product_lookup'];

		$this->assertStringContainsString( 'CREATE TABLE wp_wc_subscription_product_lookup', $sql );
		$this->assertStringContainsString( 'line_item_id bigint(20) unsigned NOT NULL', $sql );
		$this->assertStringContainsString( 'product_qty decimal(26,8) NOT NULL DEFAULT 0', $sql );
		$this->assertStringContainsString( 'line_total decimal(26,8) NOT NULL DEFAULT 0', $sql );
		$this->assertStringContainsString( 'PRIMARY KEY  (subscription_id,line_item_id)', $sql );
		$this->assertStringContainsString( 'KEY product_variation (product_id,variation_id)', $sql );
	}

	/**
	 * Test full schema SQL includes both plugin-owned tables.
	 *
	 * @return void
	 */
	public function test_schema_sql_contains_all_tables(): void {
		$sql = Schema::get_schema_sql( 'wp_', 'DEFAULT CHARACTER SET utf8mb4' );

		$this->assertStringContainsString( 'CREATE TABLE wp_wc_subscriptions_stats', $sql );
		$this->assertStringContainsString( 'CREATE TABLE wp_wc_subscription_product_lookup', $sql );
		$this->assertStringContainsString( 'DEFAULT CHARACTER SET utf8mb4', $sql );
	}
}
