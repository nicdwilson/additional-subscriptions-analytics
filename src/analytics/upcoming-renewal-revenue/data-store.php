<?php
/**
 * WooCommerce Analytics data store for upcoming renewal revenue.
 *
 * @package AdditionalSubscriptionsAnalytics
 * @since   0.9.5
 */

namespace AdditionalSubscriptionsAnalytics\Analytics\UpcomingRenewalRevenue;

use AdditionalSubscriptionsAnalytics\Data\UpcomingRenewalRevenueQuery;

defined( 'ABSPATH' ) || exit;

/**
 * Adapts upcoming renewal revenue query results for WooCommerce Analytics controllers.
 *
 * @since 0.9.5
 */
final class DataStore {

	/**
	 * Upcoming renewal revenue query.
	 *
	 * @var UpcomingRenewalRevenueQuery
	 */
	private UpcomingRenewalRevenueQuery $query;

	/**
	 * Constructor.
	 *
	 * @since 0.9.5
	 *
	 * @param UpcomingRenewalRevenueQuery|null $query Optional query dependency.
	 */
	public function __construct( ?UpcomingRenewalRevenueQuery $query = null ) {
		$this->query = $query ?? new UpcomingRenewalRevenueQuery();
	}

	/**
	 * Get report data.
	 *
	 * @since 0.9.5
	 *
	 * @param array<string, mixed> $args Query parameters.
	 *
	 * @return \stdClass Data object expected by WooCommerce Analytics GenericController.
	 */
	public function get_data( $args ): \stdClass {
		$results = $this->query->get_data( \is_array( $args ) ? $args : array() );

		return (object) array(
			'data'    => \array_map(
				static function ( array $row ): \stdClass {
					return (object) $row;
				},
				$results['data']
			),
			'totals'  => (object) $results['totals'],
			'page_no' => $results['page_no'],
			'pages'   => $results['pages'],
			'total'   => $results['total'],
		);
	}
}
