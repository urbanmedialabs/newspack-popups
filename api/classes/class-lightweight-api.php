<?php
/**
 * Newspack Campaigns lightweight API
 *
 * @package Newspack
 * @phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
 */

/**
 * API endpoints
 */
class Lightweight_API {

	/**
	 * Response object.
	 *
	 * @var response
	 */
	public $response = [];

	/**
	 * Debugging info.
	 *
	 * @var debug
	 */
	public $debug;

	/**
	 * Constructor.
	 *
	 * @codeCoverageIgnore
	 */
	public function __construct() {
		if ( ! $this->verify_referer() ) {
			$this->error( 'invalid_referer' );
		}
		$this->debug = [
			'read_query_count'       => 0,
			'write_query_count'      => 0,
			'cache_count'            => 0,
			'read_empty_transients'  => 0,
			'write_empty_transients' => 0,
			'write_read_query_count' => 0,
			'start_time'             => microtime( true ),
			'end_time'               => null,
			'duration'               => null,
		];
	}

	/**
	 * Verify referer is valid.
	 *
	 * @codeCoverageIgnore
	 */
	public function verify_referer() {
		$http_referer = ! empty( $_SERVER['HTTP_REFERER'] ) ? parse_url( $_SERVER['HTTP_REFERER'] , PHP_URL_HOST ) : null; // phpcs:ignore
		$valid_referers = [
			$http_referer,
			// TODO: Add AMP Cache.
		];
		$http_host = ! empty( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : null; // phpcs:ignore
		return ! empty( $http_referer ) && ! empty( $http_host ) && in_array( strtolower( $http_host ), $valid_referers, true );
	}

	/**
	 * Get transient name.
	 *
	 * @param string $client_id Client ID.
	 * @param string $popup_id Popup ID.
	 */
	public function get_transient_name( $client_id, $popup_id = null ) {
		if ( null === $popup_id ) {
			// For data about popups in general.
			return sprintf( '%s-popups', $client_id );
		}
		return sprintf( '%s-%s-popup', $client_id, $popup_id );
	}

	/**
	 * Complete the API and print response.
	 *
	 * @codeCoverageIgnore
	 */
	public function respond() {
		$this->debug['end_time'] = microtime( true );
		$this->debug['duration'] = $this->debug['end_time'] - $this->debug['start_time'];
		if ( defined( 'NEWSPACK_POPUPS_DEBUG' ) && NEWSPACK_POPUPS_DEBUG ) {
			$this->response['debug'] = $this->debug;
		}
		http_response_code( 200 );
		print json_encode( $this->response ); // phpcs:ignore
		exit;
	}

	/**
	 * Return a 400 code error.
	 *
	 * @param string $code The error code.
	 */
	public function error( $code ) {
		http_response_code( 400 );
		print json_encode( [ 'error' => $code ] ); // phpcs:ignore
		exit;
	}

	/**
	 * Get data from transient.
	 *
	 * @param string $name The transient's name.
	 */
	public function get_transient( $name ) {
		global $wpdb;
		$name = '_transient_' . $name;

		$value = wp_cache_get( $name, 'newspack-popups' );
		if ( -1 === $value ) {
			$this->debug['read_empty_transients'] += 1;
			$this->debug['cache_count']           += 1;
			return null;
		} elseif ( false === $value ) {
			$this->debug['read_query_count'] += 1;
			$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s LIMIT 1", $name ) ); // phpcs:ignore
			if ( $value ) {
				wp_cache_set( $name, $value, 'newspack-popups' );
			} else {
				$this->debug['write_empty_transients'] += 1;
				wp_cache_set( $name, -1, 'newspack-popups' );
			}
		} else {
			$this->debug['cache_count'] += 1;
		}
		return maybe_unserialize( $value );
	}

	/**
	 * Upsert transient.
	 *
	 * @param string $name THe transient's name.
	 * @param string $value THe transient's value.
	 */
	public function set_transient( $name, $value ) {
		global $wpdb;
		$name             = '_transient_' . $name;
		$serialized_value = maybe_serialize( $value );
		$autoload         = 'no';
		wp_cache_set( $name, $serialized_value, 'newspack-popups' );
		$result           = $wpdb->query( $wpdb->prepare( "INSERT INTO `$wpdb->options` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE `option_name` = VALUES(`option_name`), `option_value` = VALUES(`option_value`), `autoload` = VALUES(`autoload`)", $name, $serialized_value, $autoload ) ); // phpcs:ignore

		$this->debug['write_query_count'] += 1;
	}

	/**
	 * Retrieve campaign data.
	 *
	 * @param string $client_id Client ID.
	 * @param string $campaign_id Campaign ID.
	 * @return object Campaign data.
	 */
	public function get_campaign_data( $client_id, $campaign_id ) {
		$data = $this->get_transient( $this->get_transient_name( $client_id, $campaign_id ) );
		return [
			'count'            => ! empty( $data['count'] ) ? (int) $data['count'] : 0,
			'last_viewed'      => ! empty( $data['last_viewed'] ) ? (int) $data['last_viewed'] : 0,
			// Primarily caused by permanent dismissal, but also by email signup
			// (on a newsletter campaign) or a UTM param suppression.
			'suppress_forever' => ! empty( $data['suppress_forever'] ) ? (int) $data['suppress_forever'] : false,
		];
	}

	/**
	 * Save campaign data.
	 *
	 * @param string $client_id Client ID.
	 * @param string $campaign_id Campaign ID.
	 * @param string $campaign_data Campaign data.
	 */
	public function save_campaign_data( $client_id, $campaign_id, $campaign_data ) {
		return $this->set_transient( $this->get_transient_name( $client_id, $campaign_id ), $campaign_data );
	}

	/**
	 * Retrieve client data.
	 *
	 * @param string $client_id Client ID.
	 */
	public function get_client_data( $client_id ) {
		$data = $this->get_transient( $this->get_transient_name( $client_id ) );
		if ( ! $data ) {
			return [
				'suppressed_newsletter_campaign' => false,
			];
		}
		return $data;
	}

	/**
	 * Save client data.
	 *
	 * @param string $client_id Client ID.
	 * @param string $client_data Client data.
	 */
	public function save_client_data( $client_id, $client_data ) {
		return $this->set_transient( $this->get_transient_name( $client_id ), $client_data );
	}
}