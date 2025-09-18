<?php
/**
 * AP2 Migration Handler
 *
 * Handles migration between legacy post storage and HPOS.
 *
 * @package AP2Gateway
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * AP2 Migration Handler Class.
 */
class AP2_Migration_Handler {

	/**
	 * Migration batch size.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 50;

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Register migration hooks.
		add_action( 'admin_init', array( $this, 'maybe_migrate' ) );
		add_action( 'ap2_migrate_batch', array( $this, 'migrate_batch' ) );

		// Add admin notice for migration.
		add_action( 'admin_notices', array( $this, 'migration_notice' ) );

		// Register WP-CLI commands if available.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'ap2 migrate', array( $this, 'cli_migrate' ) );
			WP_CLI::add_command( 'ap2 verify', array( $this, 'cli_verify' ) );
			WP_CLI::add_command( 'ap2 rollback', array( $this, 'cli_rollback' ) );
		}
	}

	/**
	 * Check if migration is needed.
	 */
	public function maybe_migrate() {
		if ( ! $this->needs_migration() ) {
			return;
		}

		// Check for manual trigger.
		if ( isset( $_GET['ap2_migrate'] ) && current_user_can( 'manage_woocommerce' ) ) {
			check_admin_referer( 'ap2_migration' );
			$this->start_migration();
		}
	}

	/**
	 * Check if migration is needed.
	 *
	 * @return bool True if migration needed.
	 */
	public function needs_migration() {
		// Check if HPOS is enabled.
		if ( ! $this->is_hpos_enabled() ) {
			return false;
		}

		// Check if we have unmigrated orders.
		return $this->has_unmigrated_orders();
	}

	/**
	 * Check for unmigrated orders.
	 *
	 * @return bool True if unmigrated orders exist.
	 */
	private function has_unmigrated_orders() {
		global $wpdb;

		// Check for agent orders without HPOS migration flag.
		$count = $wpdb->get_var(
			"
			SELECT COUNT(*)
			FROM {$wpdb->postmeta} pm1
			LEFT JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id
				AND pm2.meta_key = '_ap2_hpos_migrated'
			WHERE pm1.meta_key = '_ap2_is_agent_order'
			AND pm1.meta_value = 'yes'
			AND pm2.meta_value IS NULL
		"
		);

		return $count > 0;
	}

	/**
	 * Start migration process.
	 */
	public function start_migration() {
		// Set migration status.
		update_option( 'ap2_migration_status', 'in_progress' );
		update_option( 'ap2_migration_started', current_time( 'mysql' ) );

		// Schedule first batch.
		$this->schedule_next_batch();

		// Redirect with success message.
		wp_safe_redirect(
			add_query_arg(
				array(
					'ap2_migration' => 'started',
					'_wpnonce'      => wp_create_nonce( 'ap2_migration_notice' ),
				),
				admin_url( 'admin.php?page=wc-settings' )
			)
		);
		exit;
	}

	/**
	 * Schedule next migration batch.
	 */
	private function schedule_next_batch() {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'ap2_migrate_batch', array(), 'ap2_migration' );
		} else {
			// Fallback to direct processing.
			$this->migrate_batch();
		}
	}

	/**
	 * Migrate a batch of orders.
	 */
	public function migrate_batch() {
		global $wpdb;

		// Get unmigrated orders.
		$order_ids = $wpdb->get_col(
			$wpdb->prepare(
				"
			SELECT DISTINCT pm1.post_id
			FROM {$wpdb->postmeta} pm1
			LEFT JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id
				AND pm2.meta_key = '_ap2_hpos_migrated'
			WHERE pm1.meta_key = '_ap2_is_agent_order'
			AND pm1.meta_value = 'yes'
			AND pm2.meta_value IS NULL
			LIMIT %d
		",
				self::BATCH_SIZE
			)
		);

		if ( empty( $order_ids ) ) {
			// Migration complete.
			$this->complete_migration();
			return;
		}

		// Process each order.
		foreach ( $order_ids as $order_id ) {
			$this->migrate_order( $order_id );
		}

		// Update progress.
		$this->update_migration_progress( count( $order_ids ) );

		// Schedule next batch.
		$this->schedule_next_batch();
	}

	/**
	 * Migrate single order to HPOS.
	 *
	 * @param int $order_id Order ID.
	 * @return bool Success status.
	 */
	public function migrate_order( $order_id ) {
		// Get order object.
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			$this->log_migration_error( $order_id, 'Order not found' );
			return false;
		}

		try {
			// Get legacy meta data.
			$legacy_meta = $this->get_legacy_agent_meta( $order_id );

			// Migrate to HPOS format.
			foreach ( $legacy_meta as $key => $value ) {
				$order->update_meta_data( $key, $value );
			}

			// Add migration flag.
			$order->update_meta_data( '_ap2_hpos_migrated', 'yes' );
			$order->update_meta_data( '_ap2_migration_date', current_time( 'mysql' ) );

			// Save order.
			$order->save();

			// Update custom index if exists.
			$this->update_custom_index( $order );

			// Verify migration.
			if ( $this->verify_order_migration( $order_id ) ) {
				$this->log_migration_success( $order_id );
				return true;
			} else {
				throw new Exception( 'Migration verification failed' );
			}
		} catch ( Exception $e ) {
			$this->log_migration_error( $order_id, $e->getMessage() );
			return false;
		}
	}

	/**
	 * Get legacy agent meta data.
	 *
	 * @param int $order_id Order ID.
	 * @return array Meta data.
	 */
	private function get_legacy_agent_meta( $order_id ) {
		global $wpdb;

		$meta_keys = array(
			'_ap2_is_agent_order',
			'_ap2_agent_id',
			'_ap2_mandate_token',
			'_ap2_transaction_type',
			'_ap2_transaction_id',
			'_ap2_payment_timestamp',
			'_ap2_audit_trail',
			'_ap2_processing_time',
		);

		$meta_data = array();

		foreach ( $meta_keys as $key ) {
			$value = get_post_meta( $order_id, $key, true );
			if ( ! empty( $value ) ) {
				$meta_data[ $key ] = $value;
			}
		}

		return $meta_data;
	}

	/**
	 * Update custom index for migrated order.
	 *
	 * @param WC_Order $order Order object.
	 */
	private function update_custom_index( $order ) {
		if ( class_exists( 'AP2_HPOS_Optimizer' ) ) {
			$optimizer = new AP2_HPOS_Optimizer();
			$optimizer->update_order_index( $order->get_id() );
		}
	}

	/**
	 * Verify order migration.
	 *
	 * @param int $order_id Order ID.
	 * @return bool True if verified.
	 */
	private function verify_order_migration( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return false;
		}

		// Check critical meta data.
		$required_meta = array(
			'_ap2_is_agent_order',
			'_ap2_agent_id',
			'_ap2_hpos_migrated',
		);

		foreach ( $required_meta as $key ) {
			if ( empty( $order->get_meta( $key ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Complete migration process.
	 */
	private function complete_migration() {
		// Update status.
		update_option( 'ap2_migration_status', 'completed' );
		update_option( 'ap2_migration_completed', current_time( 'mysql' ) );

		// Clear caches.
		$this->clear_caches();

		// Run data integrity check.
		$this->verify_data_integrity();

		// Send completion notification.
		$this->send_completion_notification();
	}

	/**
	 * Update migration progress.
	 *
	 * @param int $processed Number processed.
	 */
	private function update_migration_progress( $processed ) {
		$current = get_option( 'ap2_migration_processed', 0 );
		update_option( 'ap2_migration_processed', $current + $processed );
	}

	/**
	 * Clear all relevant caches.
	 */
	private function clear_caches() {
		// Clear WordPress cache.
		wp_cache_flush();

		// Clear WooCommerce caches.
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}

		// Clear AP2 caches.
		delete_transient( AP2_Agent_Detector::STATS_TRANSIENT_KEY );
		wp_cache_delete_group( AP2_HPOS_Optimizer::CACHE_GROUP );
	}

	/**
	 * Verify data integrity after migration.
	 *
	 * @return array Integrity check results.
	 */
	public function verify_data_integrity() {
		global $wpdb;

		$results = array(
			'total_orders' => 0,
			'verified'     => 0,
			'errors'       => array(),
		);

		// Get all migrated orders.
		$order_ids = $wpdb->get_col(
			"
			SELECT post_id
			FROM {$wpdb->postmeta}
			WHERE meta_key = '_ap2_hpos_migrated'
			AND meta_value = 'yes'
		"
		);

		$results['total_orders'] = count( $order_ids );

		foreach ( $order_ids as $order_id ) {
			if ( $this->verify_order_migration( $order_id ) ) {
				++$results['verified'];
			} else {
				$results['errors'][] = $order_id;
			}
		}

		// Store results.
		update_option( 'ap2_migration_integrity_check', $results );

		return $results;
	}

	/**
	 * Send migration completion notification.
	 */
	private function send_completion_notification() {
		$admin_email = get_option( 'admin_email' );
		$site_name   = get_bloginfo( 'name' );

		$subject = sprintf( __( '[%s] AP2 Gateway HPOS Migration Complete', 'ap2-gateway' ), $site_name );

		$stats = array(
			'processed' => get_option( 'ap2_migration_processed', 0 ),
			'started'   => get_option( 'ap2_migration_started' ),
			'completed' => get_option( 'ap2_migration_completed' ),
		);

		$message = sprintf(
			__(
				'The AP2 Gateway HPOS migration has been completed successfully.

Orders Migrated: %1$d
Started: %2$s
Completed: %3$s

Please verify your agent orders are working correctly.',
				'ap2-gateway'
			),
			$stats['processed'],
			$stats['started'],
			$stats['completed']
		);

		wp_mail( $admin_email, $subject, $message );
	}

	/**
	 * Display migration notice in admin.
	 */
	public function migration_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$status = get_option( 'ap2_migration_status' );

		if ( 'in_progress' === $status ) {
			$processed = get_option( 'ap2_migration_processed', 0 );
			?>
			<div class="notice notice-info">
				<p>
					<?php
					printf(
						/* translators: %d: number of orders processed */
						esc_html__( 'AP2 Gateway HPOS migration in progress. Orders processed: %d', 'ap2-gateway' ),
						esc_html( $processed )
					);
					?>
				</p>
			</div>
			<?php
		} elseif ( $this->needs_migration() ) {
			$migration_url = wp_nonce_url(
				add_query_arg( 'ap2_migrate', 'start', admin_url( 'admin.php?page=wc-settings' ) ),
				'ap2_migration'
			);
			?>
			<div class="notice notice-warning">
				<p>
					<?php esc_html_e( 'AP2 Gateway needs to migrate agent orders to the new HPOS storage system.', 'ap2-gateway' ); ?>
					<a href="<?php echo esc_url( $migration_url ); ?>" class="button button-primary">
						<?php esc_html_e( 'Start Migration', 'ap2-gateway' ); ?>
					</a>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Rollback migration.
	 *
	 * @return bool Success status.
	 */
	public function rollback_migration() {
		global $wpdb;

		// Get migrated orders.
		$order_ids = $wpdb->get_col(
			"
			SELECT post_id
			FROM {$wpdb->postmeta}
			WHERE meta_key = '_ap2_hpos_migrated'
			AND meta_value = 'yes'
		"
		);

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->delete_meta_data( '_ap2_hpos_migrated' );
				$order->delete_meta_data( '_ap2_migration_date' );
				$order->save();
			}
		}

		// Reset migration status.
		delete_option( 'ap2_migration_status' );
		delete_option( 'ap2_migration_processed' );
		delete_option( 'ap2_migration_started' );
		delete_option( 'ap2_migration_completed' );

		return true;
	}

	/**
	 * WP-CLI: Migrate orders.
	 *
	 * @param array $args CLI arguments.
	 * @param array $assoc_args Associated arguments.
	 */
	public function cli_migrate( $args, $assoc_args ) {
		WP_CLI::line( 'Starting AP2 Gateway HPOS migration...' );

		$batch_size = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : self::BATCH_SIZE;
		$total      = $this->get_total_unmigrated();

		WP_CLI::line( sprintf( 'Found %d orders to migrate', $total ) );

		$progress = \WP_CLI\Utils\make_progress_bar( 'Migrating orders', $total );

		while ( $this->has_unmigrated_orders() ) {
			$this->migrate_batch();
			$progress->tick( $batch_size );
		}

		$progress->finish();

		WP_CLI::success( 'Migration completed!' );

		// Run verification.
		$this->cli_verify( array(), array() );
	}

	/**
	 * WP-CLI: Verify migration.
	 *
	 * @param array $args CLI arguments.
	 * @param array $assoc_args Associated arguments.
	 */
	public function cli_verify( $args, $assoc_args ) {
		WP_CLI::line( 'Verifying migration integrity...' );

		$results = $this->verify_data_integrity();

		WP_CLI::line( sprintf( 'Total orders: %d', $results['total_orders'] ) );
		WP_CLI::line( sprintf( 'Verified: %d', $results['verified'] ) );

		if ( ! empty( $results['errors'] ) ) {
			WP_CLI::warning( sprintf( 'Errors found in %d orders', count( $results['errors'] ) ) );

			if ( isset( $assoc_args['verbose'] ) ) {
				foreach ( $results['errors'] as $order_id ) {
					WP_CLI::line( sprintf( '  - Order #%d', $order_id ) );
				}
			}
		} else {
			WP_CLI::success( 'All orders verified successfully!' );
		}
	}

	/**
	 * WP-CLI: Rollback migration.
	 *
	 * @param array $args CLI arguments.
	 * @param array $assoc_args Associated arguments.
	 */
	public function cli_rollback( $args, $assoc_args ) {
		WP_CLI::confirm( 'Are you sure you want to rollback the migration?' );

		WP_CLI::line( 'Rolling back migration...' );

		if ( $this->rollback_migration() ) {
			WP_CLI::success( 'Migration rolled back successfully!' );
		} else {
			WP_CLI::error( 'Rollback failed!' );
		}
	}

	/**
	 * Get total unmigrated orders count.
	 *
	 * @return int Count.
	 */
	private function get_total_unmigrated() {
		global $wpdb;

		return $wpdb->get_var(
			"
			SELECT COUNT(*)
			FROM {$wpdb->postmeta} pm1
			LEFT JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id
				AND pm2.meta_key = '_ap2_hpos_migrated'
			WHERE pm1.meta_key = '_ap2_is_agent_order'
			AND pm1.meta_value = 'yes'
			AND pm2.meta_value IS NULL
		"
		);
	}

	/**
	 * Log migration success.
	 *
	 * @param int $order_id Order ID.
	 */
	private function log_migration_success( $order_id ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( 'AP2 Migration: Successfully migrated order #%d', $order_id ) );
		}
	}

	/**
	 * Log migration error.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $error Error message.
	 */
	private function log_migration_error( $order_id, $error ) {
		error_log( sprintf( 'AP2 Migration Error: Order #%d - %s', $order_id, $error ) );

		// Store error for review.
		$errors              = get_option( 'ap2_migration_errors', array() );
		$errors[ $order_id ] = array(
			'error' => $error,
			'time'  => current_time( 'mysql' ),
		);
		update_option( 'ap2_migration_errors', $errors );
	}

	/**
	 * Check if HPOS is enabled.
	 *
	 * @return bool True if enabled.
	 */
	private function is_hpos_enabled() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) &&
				OrderUtil::custom_orders_table_usage_is_enabled();
	}
}

// Initialize migration handler.
new AP2_Migration_Handler();