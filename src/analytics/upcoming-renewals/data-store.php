<?php
/**
 * WooCommerce Analytics data store for upcoming renewals.
 *
 * @package AdditionalSubscriptionsAnalytics
 * @since   0.1.0
 */

namespace AdditionalSubscriptionsAnalytics\Analytics\UpcomingRenewals;

use AdditionalSubscriptionsAnalytics\Data\UpcomingRenewalsQuery;

defined( 'ABSPATH' ) || exit;

/**
 * Adapts upcoming renewal query results for WooCommerce Analytics controllers.
 *
 * @since 0.1.0
 */
final class DataStore {

	/**
	 * Upcoming renewals query.
	 *
	 * @var UpcomingRenewalsQuery
	 */
	private UpcomingRenewalsQuery $query;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param UpcomingRenewalsQuery|null $query Optional query dependency.
	 */
	public function __construct( ?UpcomingRenewalsQuery $query = null ) {
		$this->query = $query ?? new UpcomingRenewalsQuery();
	}

	/**
	 * Get report data.
	 *
	 * @since 0.1.0
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
