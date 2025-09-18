<?php
/**
 * AP2 Gateway Performance Monitor
 *
 * Monitors and optimizes HPOS operations performance.
 *
 * @package AP2_Gateway
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AP2_Performance_Monitor class.
 */
class AP2_Performance_Monitor {

	/**
	 * Single instance.
	 *
	 * @var AP2_Performance_Monitor
	 */
	protected static $instance = null;

	/**
	 * Performance metrics.
	 *
	 * @var array
	 */
	private $metrics = array();

	/**
	 * Query log.
	 *
	 * @var array
	 */
	private $query_log = array();

	/**
	 * Debug mode.
	 *
	 * @var bool
	 */
	private $debug_mode = false;

	/**
	 * Cache group.
	 *
	 * @var string
	 */
	private $cache_group = 'ap2_performance';

	/**
	 * Get instance.
	 *
	 * @return AP2_Performance_Monitor
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->debug_mode = defined('AP2_DEBUG') && AP2_DEBUG;

		// Initialize hooks
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 20 );
		add_action( 'wp_ajax_ap2_run_health_check', array( $this, 'ajax_run_health_check' ) );
		add_action( 'wp_ajax_ap2_export_performance_report', array( $this, 'ajax_export_report' ) );
		add_action( 'wp_ajax_ap2_analyze_distribution', array( $this, 'ajax_analyze_distribution' ) );
		add_action( 'wp_ajax_ap2_clear_performance_cache', array( $this, 'ajax_clear_cache' ) );

		// Query monitoring hooks
		if ( $this->debug_mode ) {
			add_filter( 'query', array( $this, 'log_query' ) );
			add_action( 'admin_notices', array( $this, 'show_query_debug' ) );
		}

		// Performance tracking
		add_action( 'woocommerce_before_order_object_save', array( $this, 'start_order_timer' ) );
		add_action( 'woocommerce_after_order_object_save', array( $this, 'end_order_timer' ) );
	}

	/**
	 * Initialize.
	 */
	public function init() {
		// Schedule health checks
		if ( ! wp_next_scheduled( 'ap2_performance_health_check' ) ) {
			wp_schedule_event( time(), 'daily', 'ap2_performance_health_check' );
		}
		add_action( 'ap2_performance_health_check', array( $this, 'run_health_check' ) );
	}

	/**
	 * Start order save timer.
	 *
	 * @param WC_Order $order Order object.
	 */
	public function start_order_timer( $order ) {
		if ( $this->is_agent_order( $order ) ) {
			$this->metrics['order_save_start'] = microtime( true );
		}
	}

	/**
	 * End order save timer.
	 *
	 * @param WC_Order $order Order object.
	 */
	public function end_order_timer( $order ) {
		if ( $this->is_agent_order( $order ) && isset( $this->metrics['order_save_start'] ) ) {
			$execution_time = microtime( true ) - $this->metrics['order_save_start'];
			$this->log_performance_metric( 'order_save', $execution_time, array(
				'order_id' => $order->get_id(),
				'storage_type' => $this->get_storage_type(),
				'agent_id' => $order->get_meta( '_ap2_agent_id' ),
			));
			unset( $this->metrics['order_save_start'] );
		}
	}

	/**
	 * Check if order is an agent order.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	private function is_agent_order( $order ) {
		return $order->get_payment_method() === 'ap2_gateway' || $order->get_meta( '_ap2_agent_id' );
	}

	/**
	 * Get storage type (HPOS or legacy).
	 *
	 * @return string
	 */
	private function get_storage_type() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'hpos' : 'legacy';
		}
		return 'legacy';
	}

	/**
	 * Log performance metric.
	 *
	 * @param string $operation Operation name.
	 * @param float $execution_time Execution time.
	 * @param array $context Additional context.
	 */
	public function log_performance_metric( $operation, $execution_time, $context = array() ) {
		global $wpdb;

		$data = array(
			'operation' => $operation,
			'execution_time' => $execution_time,
			'context' => wp_json_encode( $context ),
			'timestamp' => current_time( 'mysql' ),
			'storage_type' => $this->get_storage_type(),
		);

		// Store in database
		$wpdb->insert(
			$wpdb->prefix . 'ap2_performance_log',
			$data,
			array( '%s', '%f', '%s', '%s', '%s' )
		);

		// Store recent metrics in memory for quick access
		$this->metrics[] = $data;

		// Alert if slow query
		if ( $execution_time > 1.0 ) {
			$this->alert_slow_operation( $operation, $execution_time, $context );
		}
	}

	/**
	 * Log database query.
	 *
	 * @param string $query SQL query.
	 * @return string
	 */
	public function log_query( $query ) {
		if ( strpos( $query, 'ap2' ) !== false || strpos( $query, 'wc_orders' ) !== false ) {
			$start = microtime( true );
			$backtrace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 5 );

			$this->query_log[] = array(
				'query' => $query,
				'time' => microtime( true ) - $start,
				'backtrace' => $backtrace,
				'explain' => $this->get_query_explain( $query ),
			);
		}
		return $query;
	}

	/**
	 * Get query EXPLAIN.
	 *
	 * @param string $query SQL query.
	 * @return array
	 */
	private function get_query_explain( $query ) {
		global $wpdb;

		if ( strpos( strtoupper( $query ), 'SELECT' ) === 0 ) {
			$explain = $wpdb->get_results( "EXPLAIN $query", ARRAY_A );
			return $explain;
		}
		return array();
	}

	/**
	 * Run health check.
	 */
	public function run_health_check() {
		$issues = array();

		// Check HPOS tables
		$issues = array_merge( $issues, $this->check_hpos_tables() );

		// Check indexes
		$issues = array_merge( $issues, $this->check_indexes() );

		// Check data consistency
		$issues = array_merge( $issues, $this->check_data_consistency() );

		// Check performance metrics
		$issues = array_merge( $issues, $this->check_performance_metrics() );

		// Store results
		update_option( 'ap2_health_check_results', array(
			'timestamp' => time(),
			'issues' => $issues,
			'status' => empty( $issues ) ? 'healthy' : 'issues_found',
		));

		// Send alerts if critical issues
		if ( ! empty( $issues ) ) {
			$this->send_health_alerts( $issues );
		}

		return $issues;
	}

	/**
	 * Check HPOS tables.
	 *
	 * @return array Issues found.
	 */
	private function check_hpos_tables() {
		global $wpdb;
		$issues = array();

		// Check if HPOS is enabled
		if ( $this->get_storage_type() === 'hpos' ) {
			// Check wc_orders table
			$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}wc_orders'" );
			if ( ! $table_exists ) {
				$issues[] = array(
					'severity' => 'critical',
					'message' => 'HPOS table wc_orders does not exist',
					'recommendation' => 'Run WooCommerce database update',
				);
			}

			// Check wc_order_meta table
			$meta_table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}wc_order_meta'" );
			if ( ! $meta_table_exists ) {
				$issues[] = array(
					'severity' => 'critical',
					'message' => 'HPOS table wc_order_meta does not exist',
					'recommendation' => 'Run WooCommerce database update',
				);
			}
		}

		// Check custom AP2 tables
		$ap2_table = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}ap2_agent_order_index'" );
		if ( ! $ap2_table ) {
			$issues[] = array(
				'severity' => 'warning',
				'message' => 'AP2 custom index table does not exist',
				'recommendation' => 'Re-activate the AP2 Gateway plugin',
			);
		}

		return $issues;
	}

	/**
	 * Check indexes.
	 *
	 * @return array Issues found.
	 */
	private function check_indexes() {
		global $wpdb;
		$issues = array();

		// Check indexes on wc_order_meta
		if ( $this->get_storage_type() === 'hpos' ) {
			$indexes = $wpdb->get_results(
				"SHOW INDEX FROM {$wpdb->prefix}wc_order_meta WHERE Key_name = 'meta_key'"
			);

			if ( empty( $indexes ) ) {
				$issues[] = array(
					'severity' => 'warning',
					'message' => 'Missing index on wc_order_meta.meta_key',
					'recommendation' => 'Add index: ALTER TABLE wc_order_meta ADD INDEX meta_key (meta_key)',
				);
			}
		}

		// Check AP2 custom table indexes
		$ap2_indexes = $wpdb->get_results(
			"SHOW INDEX FROM {$wpdb->prefix}ap2_agent_order_index"
		);

		$required_indexes = array( 'idx_agent_id', 'idx_mandate_token', 'idx_payment_timestamp' );
		$existing_indexes = wp_list_pluck( $ap2_indexes, 'Key_name' );

		foreach ( $required_indexes as $index ) {
			if ( ! in_array( $index, $existing_indexes, true ) ) {
				$issues[] = array(
					'severity' => 'warning',
					'message' => "Missing index: $index on ap2_agent_order_index",
					'recommendation' => 'Re-create AP2 indexes',
				);
			}
		}

		return $issues;
	}

	/**
	 * Check data consistency.
	 *
	 * @return array Issues found.
	 */
	private function check_data_consistency() {
		global $wpdb;
		$issues = array();

		// Check for orphaned agent metadata
		$orphaned_meta = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}wc_order_meta m
			LEFT JOIN {$wpdb->prefix}wc_orders o ON m.order_id = o.id
			WHERE o.id IS NULL AND m.meta_key LIKE '_ap2_%'"
		);

		if ( $orphaned_meta > 0 ) {
			$issues[] = array(
				'severity' => 'warning',
				'message' => "Found $orphaned_meta orphaned AP2 metadata entries",
				'recommendation' => 'Run cleanup routine to remove orphaned data',
			);
		}

		// Check for missing agent IDs
		$missing_agent_ids = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders o
			WHERE o.payment_method = 'ap2_gateway'
			AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->prefix}wc_order_meta m
				WHERE m.order_id = o.id AND m.meta_key = '_ap2_agent_id'
			)"
		);

		if ( $missing_agent_ids > 0 ) {
			$issues[] = array(
				'severity' => 'error',
				'message' => "Found $missing_agent_ids orders without agent IDs",
				'recommendation' => 'Investigate and repair missing agent data',
			);
		}

		return $issues;
	}

	/**
	 * Check performance metrics.
	 *
	 * @return array Issues found.
	 */
	private function check_performance_metrics() {
		global $wpdb;
		$issues = array();

		// Get average query times for last 24 hours
		$slow_queries = $wpdb->get_results(
			"SELECT operation, AVG(execution_time) as avg_time, COUNT(*) as count
			FROM {$wpdb->prefix}ap2_performance_log
			WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
			GROUP BY operation
			HAVING avg_time > 0.5"
		);

		foreach ( $slow_queries as $query ) {
			$issues[] = array(
				'severity' => 'performance',
				'message' => sprintf(
					'Slow operation: %s (avg: %.2fs, count: %d)',
					$query->operation,
					$query->avg_time,
					$query->count
				),
				'recommendation' => 'Investigate query optimization opportunities',
			);
		}

		// Check cache hit rates
		$cache_stats = wp_cache_get_stats();
		if ( isset( $cache_stats['hits'], $cache_stats['misses'] ) ) {
			$hit_rate = $cache_stats['hits'] / ( $cache_stats['hits'] + $cache_stats['misses'] );
			if ( $hit_rate < 0.8 ) {
				$issues[] = array(
					'severity' => 'performance',
					'message' => sprintf( 'Low cache hit rate: %.1f%%', $hit_rate * 100 ),
					'recommendation' => 'Review caching strategy',
				);
			}
		}

		return $issues;
	}

	/**
	 * Alert on slow operation.
	 *
	 * @param string $operation Operation name.
	 * @param float $execution_time Execution time.
	 * @param array $context Context.
	 */
	private function alert_slow_operation( $operation, $execution_time, $context ) {
		// Log to error log
		error_log( sprintf(
			'AP2 Gateway: Slow operation detected - %s took %.2fs',
			$operation,
			$execution_time
		));

		// Store alert
		$alerts = get_transient( 'ap2_performance_alerts' ) ?: array();
		$alerts[] = array(
			'operation' => $operation,
			'execution_time' => $execution_time,
			'context' => $context,
			'timestamp' => time(),
		);

		// Keep only last 50 alerts
		$alerts = array_slice( $alerts, -50 );
		set_transient( 'ap2_performance_alerts', $alerts, DAY_IN_SECONDS );
	}

	/**
	 * Send health alerts.
	 *
	 * @param array $issues Health issues.
	 */
	private function send_health_alerts( $issues ) {
		$critical_issues = array_filter( $issues, function( $issue ) {
			return $issue['severity'] === 'critical';
		});

		if ( ! empty( $critical_issues ) ) {
			$admin_email = get_option( 'admin_email' );
			$subject = 'AP2 Gateway: Critical Health Issues Detected';
			$message = "The following critical issues were detected:\n\n";

			foreach ( $critical_issues as $issue ) {
				$message .= "- {$issue['message']}\n";
				$message .= "  Recommendation: {$issue['recommendation']}\n\n";
			}

			wp_mail( $admin_email, $subject, $message );
		}
	}

	/**
	 * Add admin menu.
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'AP2 Performance Monitor', 'ap2-gateway' ),
			__( 'AP2 Performance', 'ap2-gateway' ),
			'manage_woocommerce',
			'ap2-performance',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Render admin page.
	 */
	public function render_admin_page() {
		// Get health check results
		$health_results = get_option( 'ap2_health_check_results', array() );

		// Get performance metrics
		global $wpdb;
		$recent_metrics = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}ap2_performance_log
			ORDER BY timestamp DESC
			LIMIT 100"
		);

		// Get slow queries
		$slow_queries = $wpdb->get_results(
			"SELECT operation, AVG(execution_time) as avg_time,
			MIN(execution_time) as min_time, MAX(execution_time) as max_time,
			COUNT(*) as count
			FROM {$wpdb->prefix}ap2_performance_log
			WHERE timestamp > DATE_SUB(NOW(), INTERVAL 7 DAY)
			GROUP BY operation
			ORDER BY avg_time DESC"
		);

		// Get storage comparison
		$storage_comparison = $wpdb->get_results(
			"SELECT storage_type, AVG(execution_time) as avg_time, COUNT(*) as count
			FROM {$wpdb->prefix}ap2_performance_log
			WHERE timestamp > DATE_SUB(NOW(), INTERVAL 7 DAY)
			GROUP BY storage_type"
		);

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AP2 Performance Monitor', 'ap2-gateway' ); ?></h1>

			<!-- Health Status -->
			<div class="ap2-health-status">
				<h2><?php esc_html_e( 'System Health', 'ap2-gateway' ); ?></h2>
				<?php if ( ! empty( $health_results ) ) : ?>
					<div class="health-status-badge <?php echo esc_attr( $health_results['status'] ); ?>">
						<?php echo esc_html( ucfirst( str_replace( '_', ' ', $health_results['status'] ) ) ); ?>
					</div>
					<p><?php
						printf(
							esc_html__( 'Last checked: %s', 'ap2-gateway' ),
							esc_html( human_time_diff( $health_results['timestamp'] ) . ' ago' )
						);
					?></p>

					<?php if ( ! empty( $health_results['issues'] ) ) : ?>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Severity', 'ap2-gateway' ); ?></th>
									<th><?php esc_html_e( 'Issue', 'ap2-gateway' ); ?></th>
									<th><?php esc_html_e( 'Recommendation', 'ap2-gateway' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $health_results['issues'] as $issue ) : ?>
									<tr>
										<td><span class="severity-<?php echo esc_attr( $issue['severity'] ); ?>">
											<?php echo esc_html( $issue['severity'] ); ?>
										</span></td>
										<td><?php echo esc_html( $issue['message'] ); ?></td>
										<td><?php echo esc_html( $issue['recommendation'] ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p class="success"><?php esc_html_e( 'No issues detected!', 'ap2-gateway' ); ?></p>
					<?php endif; ?>
				<?php endif; ?>

				<p>
					<button id="run-health-check" class="button button-primary">
						<?php esc_html_e( 'Run Health Check Now', 'ap2-gateway' ); ?>
					</button>
				</p>
			</div>

			<!-- Performance Metrics -->
			<div class="ap2-performance-metrics">
				<h2><?php esc_html_e( 'Performance Metrics', 'ap2-gateway' ); ?></h2>

				<!-- Storage Type Comparison -->
				<?php if ( ! empty( $storage_comparison ) ) : ?>
					<h3><?php esc_html_e( 'HPOS vs Legacy Performance', 'ap2-gateway' ); ?></h3>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Storage Type', 'ap2-gateway' ); ?></th>
								<th><?php esc_html_e( 'Avg Time (s)', 'ap2-gateway' ); ?></th>
								<th><?php esc_html_e( 'Operations', 'ap2-gateway' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $storage_comparison as $storage ) : ?>
								<tr>
									<td><?php echo esc_html( strtoupper( $storage->storage_type ) ); ?></td>
									<td><?php echo esc_html( number_format( $storage->avg_time, 4 ) ); ?></td>
									<td><?php echo esc_html( $storage->count ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<!-- Slow Queries -->
				<?php if ( ! empty( $slow_queries ) ) : ?>
					<h3><?php esc_html_e( 'Operation Performance', 'ap2-gateway' ); ?></h3>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Operation', 'ap2-gateway' ); ?></th>
								<th><?php esc_html_e( 'Avg (s)', 'ap2-gateway' ); ?></th>
								<th><?php esc_html_e( 'Min (s)', 'ap2-gateway' ); ?></th>
								<th><?php esc_html_e( 'Max (s)', 'ap2-gateway' ); ?></th>
								<th><?php esc_html_e( 'Count', 'ap2-gateway' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $slow_queries as $query ) : ?>
								<tr class="<?php echo $query->avg_time > 1 ? 'slow-query' : ''; ?>">
									<td><?php echo esc_html( $query->operation ); ?></td>
									<td><?php echo esc_html( number_format( $query->avg_time, 4 ) ); ?></td>
									<td><?php echo esc_html( number_format( $query->min_time, 4 ) ); ?></td>
									<td><?php echo esc_html( number_format( $query->max_time, 4 ) ); ?></td>
									<td><?php echo esc_html( $query->count ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<!-- Diagnostic Tools -->
			<div class="ap2-diagnostic-tools">
				<h2><?php esc_html_e( 'Diagnostic Tools', 'ap2-gateway' ); ?></h2>

				<div class="tool-buttons">
					<button id="analyze-distribution" class="button">
						<?php esc_html_e( 'Analyze Agent Order Distribution', 'ap2-gateway' ); ?>
					</button>
					<button id="export-report" class="button">
						<?php esc_html_e( 'Export Performance Report', 'ap2-gateway' ); ?>
					</button>
					<button id="clear-cache" class="button">
						<?php esc_html_e( 'Clear Performance Cache', 'ap2-gateway' ); ?>
					</button>
					<?php if ( $this->debug_mode ) : ?>
						<button id="show-query-log" class="button">
							<?php esc_html_e( 'Show Query Log', 'ap2-gateway' ); ?>
						</button>
					<?php endif; ?>
				</div>

				<div id="diagnostic-results"></div>
			</div>

			<!-- Debug Mode Toggle -->
			<div class="ap2-debug-mode">
				<h2><?php esc_html_e( 'Debug Settings', 'ap2-gateway' ); ?></h2>
				<p>
					<?php if ( $this->debug_mode ) : ?>
						<span class="debug-active"><?php esc_html_e( 'Debug mode is ACTIVE', 'ap2-gateway' ); ?></span>
						<p><?php esc_html_e( 'To disable, remove AP2_DEBUG constant from wp-config.php', 'ap2-gateway' ); ?></p>
					<?php else : ?>
						<span><?php esc_html_e( 'Debug mode is INACTIVE', 'ap2-gateway' ); ?></span>
						<p><?php esc_html_e( 'To enable, add define(\'AP2_DEBUG\', true); to wp-config.php', 'ap2-gateway' ); ?></p>
					<?php endif; ?>
				</p>
			</div>
		</div>

		<style>
			.ap2-health-status, .ap2-performance-metrics, .ap2-diagnostic-tools, .ap2-debug-mode {
				background: #fff;
				padding: 20px;
				margin: 20px 0;
				border: 1px solid #ccd0d4;
				box-shadow: 0 1px 1px rgba(0,0,0,.04);
			}
			.health-status-badge {
				display: inline-block;
				padding: 5px 15px;
				border-radius: 3px;
				font-weight: bold;
				margin: 10px 0;
			}
			.health-status-badge.healthy { background: #46b450; color: white; }
			.health-status-badge.issues_found { background: #ffb900; color: white; }
			.severity-critical { color: #dc3232; font-weight: bold; }
			.severity-error { color: #dc3232; }
			.severity-warning { color: #ffb900; }
			.severity-performance { color: #0073aa; }
			.slow-query { background-color: #fff8e5 !important; }
			.tool-buttons { margin: 20px 0; }
			.tool-buttons .button { margin-right: 10px; margin-bottom: 10px; }
			.debug-active { color: #46b450; font-weight: bold; }
			#diagnostic-results {
				margin-top: 20px;
				padding: 15px;
				background: #f1f1f1;
				border-radius: 3px;
				display: none;
			}
			#diagnostic-results.active { display: block; }
			.success { color: #46b450; font-weight: bold; }
		</style>

		<script>
		jQuery(document).ready(function($) {
			// Run health check
			$('#run-health-check').on('click', function() {
				var $button = $(this);
				$button.prop('disabled', true).text('<?php esc_html_e( 'Running...', 'ap2-gateway' ); ?>');

				$.post(ajaxurl, {
					action: 'ap2_run_health_check',
					nonce: '<?php echo esc_js( wp_create_nonce( 'ap2_health_check' ) ); ?>'
				}, function(response) {
					location.reload();
				});
			});

			// Analyze distribution
			$('#analyze-distribution').on('click', function() {
				var $results = $('#diagnostic-results');
				$results.addClass('active').html('<?php esc_html_e( 'Analyzing...', 'ap2-gateway' ); ?>');

				$.post(ajaxurl, {
					action: 'ap2_analyze_distribution',
					nonce: '<?php echo esc_js( wp_create_nonce( 'ap2_diagnostics' ) ); ?>'
				}, function(response) {
					$results.html(response.data);
				});
			});

			// Export report
			$('#export-report').on('click', function() {
				window.location.href = ajaxurl + '?action=ap2_export_performance_report&nonce=<?php echo esc_js( wp_create_nonce( 'ap2_export' ) ); ?>';
			});

			// Clear cache
			$('#clear-cache').on('click', function() {
				if (confirm('<?php esc_html_e( 'Clear all performance caches?', 'ap2-gateway' ); ?>')) {
					$.post(ajaxurl, {
						action: 'ap2_clear_performance_cache',
						nonce: '<?php echo esc_js( wp_create_nonce( 'ap2_cache' ) ); ?>'
					}, function(response) {
						alert(response.data);
					});
				}
			});
		});
		</script>
		<?php
	}

	/**
	 * AJAX handler for health check.
	 */
	public function ajax_run_health_check() {
		check_ajax_referer( 'ap2_health_check', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( -1 );
		}

		$results = $this->run_health_check();
		wp_send_json_success( $results );
	}

	/**
	 * AJAX handler for export report.
	 */
	public function ajax_export_report() {
		check_admin_referer( 'ap2_export', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( -1 );
		}

		global $wpdb;

		// Get performance data
		$data = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}ap2_performance_log
			ORDER BY timestamp DESC
			LIMIT 1000"
		);

		// Generate CSV
		header( 'Content-Type: text/csv' );
		header( 'Content-Disposition: attachment; filename="ap2-performance-report-' . date('Y-m-d') . '.csv"' );

		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, array( 'Timestamp', 'Operation', 'Execution Time', 'Storage Type', 'Context' ) );

		foreach ( $data as $row ) {
			fputcsv( $output, array(
				$row->timestamp,
				$row->operation,
				$row->execution_time,
				$row->storage_type,
				$row->context,
			));
		}

		fclose( $output );
		exit;
	}

	/**
	 * Show query debug in admin.
	 */
	public function show_query_debug() {
		if ( ! current_user_can( 'manage_woocommerce' ) || empty( $this->query_log ) ) {
			return;
		}

		?>
		<div class="notice notice-info">
			<h3><?php esc_html_e( 'AP2 Query Debug Log', 'ap2-gateway' ); ?></h3>
			<details>
				<summary><?php
					printf(
						esc_html__( '%d queries logged', 'ap2-gateway' ),
						count( $this->query_log )
					);
				?></summary>
				<pre><?php print_r( $this->query_log ); ?></pre>
			</details>
		</div>
		<?php
	}

	/**
	 * AJAX handler for analyzing agent order distribution.
	 */
	public function ajax_analyze_distribution() {
		check_ajax_referer( 'ap2_diagnostics', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( -1 );
		}

		global $wpdb;

		// Get agent order distribution.
		$distribution = $wpdb->get_results(
			"SELECT
				agent_id,
				COUNT(*) as order_count,
				AVG(total_amount) as avg_amount,
				MIN(payment_timestamp) as first_order,
				MAX(payment_timestamp) as last_order
			FROM {$wpdb->prefix}ap2_agent_order_index
			GROUP BY agent_id
			ORDER BY order_count DESC
			LIMIT 20"
		);

		// Get order status distribution.
		$status_dist = $wpdb->get_results(
			"SELECT
				o.status,
				COUNT(*) as count
			FROM {$wpdb->prefix}wc_orders o
			WHERE o.payment_method = 'ap2_gateway'
			GROUP BY o.status"
		);

		// Get time-based distribution.
		$time_dist = $wpdb->get_results(
			"SELECT
				DATE(payment_timestamp) as order_date,
				COUNT(*) as orders,
				SUM(total_amount) as total
			FROM {$wpdb->prefix}ap2_agent_order_index
			WHERE payment_timestamp > DATE_SUB(NOW(), INTERVAL 30 DAY)
			GROUP BY DATE(payment_timestamp)
			ORDER BY order_date DESC"
		);

		// Format response.
		$response = '<div class="distribution-analysis">';

		// Agent distribution.
		$response .= '<h3>' . esc_html__( 'Top Agents by Order Count', 'ap2-gateway' ) . '</h3>';
		if ( ! empty( $distribution ) ) {
			$response .= '<table class="widefat striped">';
			$response .= '<thead><tr>';
			$response .= '<th>' . esc_html__( 'Agent ID', 'ap2-gateway' ) . '</th>';
			$response .= '<th>' . esc_html__( 'Orders', 'ap2-gateway' ) . '</th>';
			$response .= '<th>' . esc_html__( 'Avg Amount', 'ap2-gateway' ) . '</th>';
			$response .= '<th>' . esc_html__( 'First Order', 'ap2-gateway' ) . '</th>';
			$response .= '<th>' . esc_html__( 'Last Order', 'ap2-gateway' ) . '</th>';
			$response .= '</tr></thead><tbody>';

			foreach ( $distribution as $agent ) {
				$response .= '<tr>';
				$response .= '<td>' . esc_html( $agent->agent_id ) . '</td>';
				$response .= '<td>' . esc_html( $agent->order_count ) . '</td>';
				$response .= '<td>' . wc_price( $agent->avg_amount ) . '</td>';
				$response .= '<td>' . esc_html( $agent->first_order ) . '</td>';
				$response .= '<td>' . esc_html( $agent->last_order ) . '</td>';
				$response .= '</tr>';
			}

			$response .= '</tbody></table>';
		} else {
			$response .= '<p>' . esc_html__( 'No agent orders found.', 'ap2-gateway' ) . '</p>';
		}

		// Status distribution.
		if ( ! empty( $status_dist ) ) {
			$response .= '<h3>' . esc_html__( 'Order Status Distribution', 'ap2-gateway' ) . '</h3>';
			$response .= '<table class="widefat striped">';
			$response .= '<thead><tr>';
			$response .= '<th>' . esc_html__( 'Status', 'ap2-gateway' ) . '</th>';
			$response .= '<th>' . esc_html__( 'Count', 'ap2-gateway' ) . '</th>';
			$response .= '</tr></thead><tbody>';

			foreach ( $status_dist as $status ) {
				$response .= '<tr>';
				$response .= '<td>' . esc_html( wc_get_order_status_name( $status->status ) ) . '</td>';
				$response .= '<td>' . esc_html( $status->count ) . '</td>';
				$response .= '</tr>';
			}

			$response .= '</tbody></table>';
		}

		// Time distribution.
		if ( ! empty( $time_dist ) ) {
			$response .= '<h3>' . esc_html__( 'Last 30 Days Activity', 'ap2-gateway' ) . '</h3>';
			$response .= '<table class="widefat striped">';
			$response .= '<thead><tr>';
			$response .= '<th>' . esc_html__( 'Date', 'ap2-gateway' ) . '</th>';
			$response .= '<th>' . esc_html__( 'Orders', 'ap2-gateway' ) . '</th>';
			$response .= '<th>' . esc_html__( 'Total Amount', 'ap2-gateway' ) . '</th>';
			$response .= '</tr></thead><tbody>';

			foreach ( $time_dist as $day ) {
				$response .= '<tr>';
				$response .= '<td>' . esc_html( $day->order_date ) . '</td>';
				$response .= '<td>' . esc_html( $day->orders ) . '</td>';
				$response .= '<td>' . wc_price( $day->total ) . '</td>';
				$response .= '</tr>';
			}

			$response .= '</tbody></table>';
		}

		$response .= '</div>';

		wp_send_json_success( $response );
	}

	/**
	 * AJAX handler for clearing performance cache.
	 */
	public function ajax_clear_cache() {
		check_ajax_referer( 'ap2_cache', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( -1 );
		}

		// Clear WordPress object cache for AP2.
		wp_cache_delete_group( $this->cache_group );
		wp_cache_delete_group( 'ap2_agent_orders' );
		wp_cache_delete_group( 'ap2_queries' );

		// Clear transients.
		global $wpdb;
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_ap2_%'
			OR option_name LIKE '_transient_timeout_ap2_%'"
		);

		// Clear query optimizer cache if available.
		if ( class_exists( 'AP2_Query_Optimizer' ) ) {
			AP2_Query_Optimizer::instance()->clear_cache();
		}

		wp_send_json_success( __( 'Performance caches cleared successfully!', 'ap2-gateway' ) );
	}

	/**
	 * Create performance tables on activation.
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ap2_performance_log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			operation varchar(100) NOT NULL,
			execution_time float NOT NULL,
			context text,
			timestamp datetime DEFAULT CURRENT_TIMESTAMP,
			storage_type varchar(20),
			PRIMARY KEY (id),
			KEY idx_operation (operation),
			KEY idx_timestamp (timestamp),
			KEY idx_execution_time (execution_time)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}

// Initialize
AP2_Performance_Monitor::instance();