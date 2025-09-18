<?php
/**
 * AP2 Query Optimizer
 *
 * Optimizes database queries for agent orders with query analysis and caching.
 *
 * @package AP2_Gateway
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AP2_Query_Optimizer class.
 */
class AP2_Query_Optimizer {

	/**
	 * Single instance.
	 *
	 * @var AP2_Query_Optimizer
	 */
	protected static $instance = null;

	/**
	 * Query cache.
	 *
	 * @var array
	 */
	private $query_cache = array();

	/**
	 * Query explain cache.
	 *
	 * @var array
	 */
	private $explain_cache = array();

	/**
	 * Query hints.
	 *
	 * @var array
	 */
	private $query_hints = array();

	/**
	 * Cache group.
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'ap2_queries';

	/**
	 * Get instance.
	 *
	 * @return AP2_Query_Optimizer
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
		// Initialize optimization hooks.
		add_filter( 'query', array( $this, 'optimize_query' ), 5 );
		add_filter( 'posts_request', array( $this, 'optimize_posts_query' ), 10, 2 );
		add_filter( 'woocommerce_order_query_args', array( $this, 'optimize_order_query_args' ), 10 );

		// Query Monitor integration.
		if ( class_exists( 'QM_Collectors' ) ) {
			add_filter( 'qm/collectors', array( $this, 'register_query_monitor_collector' ) );
		}

		// Initialize query hints.
		$this->init_query_hints();

		// Admin tools.
		add_action( 'wp_ajax_ap2_analyze_query', array( $this, 'ajax_analyze_query' ) );
		add_action( 'wp_ajax_ap2_optimize_tables', array( $this, 'ajax_optimize_tables' ) );
	}

	/**
	 * Initialize query hints for optimal performance.
	 */
	private function init_query_hints() {
		$this->query_hints = array(
			// Agent ID lookups.
			'agent_id_lookup' => array(
				'pattern' => '/meta_key\s*=\s*[\'"]_ap2_agent_id[\'"]/i',
				'hint' => 'USE INDEX (meta_key)',
				'cache_ttl' => 3600,
			),
			// Mandate token searches.
			'mandate_token_search' => array(
				'pattern' => '/meta_key\s*=\s*[\'"]_ap2_mandate_token[\'"]/i',
				'hint' => 'USE INDEX (meta_key)',
				'cache_ttl' => 3600,
			),
			// Date range queries.
			'date_range' => array(
				'pattern' => '/date_created\s*BETWEEN/i',
				'hint' => 'USE INDEX (date_created_gmt)',
				'cache_ttl' => 300,
			),
			// Order status queries.
			'order_status' => array(
				'pattern' => '/status\s*IN\s*\(/i',
				'hint' => 'USE INDEX (status)',
				'cache_ttl' => 600,
			),
		);
	}

	/**
	 * Optimize database query.
	 *
	 * @param string $query SQL query.
	 * @return string Optimized query.
	 */
	public function optimize_query( $query ) {
		// Skip non-SELECT queries.
		if ( stripos( $query, 'SELECT' ) !== 0 ) {
			return $query;
		}

		// Check if this is an agent-related query.
		if ( ! $this->is_agent_query( $query ) ) {
			return $query;
		}

		// Apply query hints.
		$query = $this->apply_query_hints( $query );

		// Check query cache.
		$cache_key = $this->get_query_cache_key( $query );
		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			// Log cache hit.
			if ( defined( 'AP2_DEBUG' ) && AP2_DEBUG ) {
				$this->log_cache_hit( $query );
			}
			return $query;
		}

		// Analyze query for optimization opportunities.
		if ( defined( 'AP2_DEBUG' ) && AP2_DEBUG ) {
			$this->analyze_query( $query );
		}

		return $query;
	}

	/**
	 * Check if query is agent-related.
	 *
	 * @param string $query SQL query.
	 * @return bool
	 */
	private function is_agent_query( $query ) {
		$agent_patterns = array(
			'ap2_',
			'_ap2_',
			'agent_id',
			'mandate_token',
			'wc_order',
		);

		foreach ( $agent_patterns as $pattern ) {
			if ( stripos( $query, $pattern ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Apply query hints for optimization.
	 *
	 * @param string $query SQL query.
	 * @return string Optimized query.
	 */
	private function apply_query_hints( $query ) {
		global $wpdb;

		foreach ( $this->query_hints as $hint_name => $hint ) {
			if ( preg_match( $hint['pattern'], $query ) ) {
				// Check if hint is already applied.
				if ( stripos( $query, $hint['hint'] ) === false ) {
					// Apply hint after FROM clause.
					$query = preg_replace(
						'/FROM\s+(' . preg_quote( $wpdb->prefix, '/' ) . '\w+)/i',
						'FROM $1 ' . $hint['hint'],
						$query,
						1
					);

					// Log hint application.
					if ( defined( 'AP2_DEBUG' ) && AP2_DEBUG ) {
						error_log( "AP2 Query Optimizer: Applied hint '$hint_name' to query" );
					}
				}
			}
		}

		return $query;
	}

	/**
	 * Analyze query and provide optimization suggestions.
	 *
	 * @param string $query SQL query.
	 * @return array Analysis results.
	 */
	public function analyze_query( $query ) {
		global $wpdb;

		$analysis = array(
			'query' => $query,
			'explain' => array(),
			'suggestions' => array(),
			'estimated_rows' => 0,
			'using_index' => false,
		);

		// Get EXPLAIN output.
		if ( stripos( $query, 'SELECT' ) === 0 ) {
			$explain = $wpdb->get_results( "EXPLAIN $query", ARRAY_A );
			$analysis['explain'] = $explain;

			if ( ! empty( $explain ) ) {
				foreach ( $explain as $row ) {
					// Check for table scans.
					if ( isset( $row['type'] ) && $row['type'] === 'ALL' ) {
						$analysis['suggestions'][] = sprintf(
							'Table scan detected on %s. Consider adding an index.',
							$row['table']
						);
					}

					// Check for filesort.
					if ( isset( $row['Extra'] ) && stripos( $row['Extra'], 'filesort' ) !== false ) {
						$analysis['suggestions'][] = 'Query uses filesort. Consider optimizing ORDER BY clause.';
					}

					// Check for temporary tables.
					if ( isset( $row['Extra'] ) && stripos( $row['Extra'], 'temporary' ) !== false ) {
						$analysis['suggestions'][] = 'Query uses temporary tables. Consider optimizing GROUP BY clause.';
					}

					// Track estimated rows.
					if ( isset( $row['rows'] ) ) {
						$analysis['estimated_rows'] += intval( $row['rows'] );
					}

					// Check index usage.
					if ( isset( $row['key'] ) && ! empty( $row['key'] ) ) {
						$analysis['using_index'] = true;
					}
				}
			}
		}

		// Provide general suggestions.
		if ( $analysis['estimated_rows'] > 1000 && ! $analysis['using_index'] ) {
			$analysis['suggestions'][] = 'Large result set without index usage. Performance may be impacted.';
		}

		// Check for missing indexes on common AP2 fields.
		if ( strpos( $query, '_ap2_agent_id' ) !== false ) {
			$has_index = $this->check_index_exists( 'wc_order_meta', 'meta_key' );
			if ( ! $has_index ) {
				$analysis['suggestions'][] = 'Missing index on meta_key for agent_id lookups.';
			}
		}

		// Store analysis for debugging.
		if ( defined( 'AP2_DEBUG' ) && AP2_DEBUG ) {
			$this->explain_cache[] = $analysis;

			// Limit cache size.
			if ( count( $this->explain_cache ) > 100 ) {
				array_shift( $this->explain_cache );
			}
		}

		return $analysis;
	}

	/**
	 * Check if index exists on table.
	 *
	 * @param string $table Table name.
	 * @param string $column Column name.
	 * @return bool
	 */
	private function check_index_exists( $table, $column ) {
		global $wpdb;

		$indexes = $wpdb->get_results(
			$wpdb->prepare(
				"SHOW INDEX FROM %i WHERE Column_name = %s",
				$wpdb->prefix . $table,
				$column
			)
		);

		return ! empty( $indexes );
	}

	/**
	 * Optimize posts query for agent orders.
	 *
	 * @param string $request SQL query.
	 * @param WP_Query $query Query object.
	 * @return string
	 */
	public function optimize_posts_query( $request, $query ) {
		// Check if this is an order query.
		if ( ! isset( $query->query_vars['post_type'] ) ||
		     ! in_array( 'shop_order', (array) $query->query_vars['post_type'], true ) ) {
			return $request;
		}

		// Apply optimizations for agent order queries.
		if ( isset( $query->query_vars['meta_query'] ) ) {
			foreach ( $query->query_vars['meta_query'] as $meta ) {
				if ( isset( $meta['key'] ) && strpos( $meta['key'], '_ap2_' ) === 0 ) {
					// This is an agent query - apply optimizations.
					$request = $this->optimize_agent_order_query( $request );
					break;
				}
			}
		}

		return $request;
	}

	/**
	 * Optimize agent order query.
	 *
	 * @param string $query SQL query.
	 * @return string
	 */
	private function optimize_agent_order_query( $query ) {
		// Add query hints for better performance.
		$optimizations = array(
			// Force index usage for meta queries.
			'/JOIN\s+(\S+postmeta)/i' => 'JOIN $1 USE INDEX (meta_key)',
			// Optimize ORDER BY for date queries.
			'/ORDER BY\s+(\S+)\.post_date/i' => 'ORDER BY $1.post_date USE INDEX (type_status_date)',
		);

		foreach ( $optimizations as $pattern => $replacement ) {
			$query = preg_replace( $pattern, $replacement, $query, 1 );
		}

		return $query;
	}

	/**
	 * Optimize WooCommerce order query arguments.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public function optimize_order_query_args( $args ) {
		// Check if this is an agent query.
		$is_agent_query = false;
		if ( isset( $args['meta_query'] ) ) {
			foreach ( $args['meta_query'] as $meta ) {
				if ( isset( $meta['key'] ) && strpos( $meta['key'], '_ap2_' ) === 0 ) {
					$is_agent_query = true;
					break;
				}
			}
		}

		if ( $is_agent_query ) {
			// Optimize pagination.
			if ( ! isset( $args['limit'] ) || $args['limit'] > 100 ) {
				$args['limit'] = 100; // Reasonable default.
			}

			// Add caching hint.
			$args['cache_results'] = true;

			// Optimize field selection if not needed.
			if ( ! isset( $args['return'] ) ) {
				$args['return'] = 'ids'; // Return only IDs if full objects not needed.
			}
		}

		return $args;
	}

	/**
	 * Get query cache key.
	 *
	 * @param string $query SQL query.
	 * @return string
	 */
	private function get_query_cache_key( $query ) {
		return 'ap2_query_' . md5( $query );
	}

	/**
	 * Log cache hit.
	 *
	 * @param string $query SQL query.
	 */
	private function log_cache_hit( $query ) {
		if ( ! isset( $this->query_cache['hits'] ) ) {
			$this->query_cache['hits'] = 0;
		}
		$this->query_cache['hits']++;
	}

	/**
	 * AJAX handler for query analysis.
	 */
	public function ajax_analyze_query() {
		check_ajax_referer( 'ap2_diagnostics', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( -1 );
		}

		$query = isset( $_POST['query'] ) ? sanitize_textarea_field( wp_unslash( $_POST['query'] ) ) : '';

		if ( empty( $query ) ) {
			wp_send_json_error( 'No query provided' );
		}

		$analysis = $this->analyze_query( $query );

		// Format response.
		$response = '<div class="query-analysis">';
		$response .= '<h3>Query Analysis</h3>';

		// Show EXPLAIN output.
		if ( ! empty( $analysis['explain'] ) ) {
			$response .= '<h4>EXPLAIN Output:</h4>';
			$response .= '<table class="widefat">';
			$response .= '<thead><tr>';
			foreach ( array_keys( $analysis['explain'][0] ) as $column ) {
				$response .= '<th>' . esc_html( $column ) . '</th>';
			}
			$response .= '</tr></thead>';
			$response .= '<tbody>';
			foreach ( $analysis['explain'] as $row ) {
				$response .= '<tr>';
				foreach ( $row as $value ) {
					$response .= '<td>' . esc_html( $value ) . '</td>';
				}
				$response .= '</tr>';
			}
			$response .= '</tbody></table>';
		}

		// Show suggestions.
		if ( ! empty( $analysis['suggestions'] ) ) {
			$response .= '<h4>Optimization Suggestions:</h4>';
			$response .= '<ul>';
			foreach ( $analysis['suggestions'] as $suggestion ) {
				$response .= '<li>' . esc_html( $suggestion ) . '</li>';
			}
			$response .= '</ul>';
		} else {
			$response .= '<p class="success">Query appears to be optimized!</p>';
		}

		// Show metrics.
		$response .= '<h4>Metrics:</h4>';
		$response .= '<ul>';
		$response .= '<li>Estimated rows: ' . esc_html( $analysis['estimated_rows'] ) . '</li>';
		$response .= '<li>Using index: ' . ( $analysis['using_index'] ? 'Yes' : 'No' ) . '</li>';
		$response .= '</ul>';

		$response .= '</div>';

		wp_send_json_success( $response );
	}

	/**
	 * AJAX handler for table optimization.
	 */
	public function ajax_optimize_tables() {
		check_ajax_referer( 'ap2_diagnostics', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( -1 );
		}

		global $wpdb;

		$tables = array(
			$wpdb->prefix . 'wc_orders',
			$wpdb->prefix . 'wc_order_meta',
			$wpdb->prefix . 'ap2_agent_order_index',
			$wpdb->prefix . 'ap2_performance_log',
		);

		$results = array();

		foreach ( $tables as $table ) {
			// Check if table exists.
			if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) === $table ) {
				// Analyze table.
				$wpdb->query( "ANALYZE TABLE `$table`" );

				// Optimize table.
				$result = $wpdb->get_results( "OPTIMIZE TABLE `$table`", ARRAY_A );

				$results[] = array(
					'table' => $table,
					'result' => isset( $result[0]['Msg_text'] ) ? $result[0]['Msg_text'] : 'Optimized',
				);
			}
		}

		wp_send_json_success( $results );
	}

	/**
	 * Register Query Monitor collector.
	 *
	 * @param array $collectors Existing collectors.
	 * @return array
	 */
	public function register_query_monitor_collector( $collectors ) {
		require_once AP2_GATEWAY_PLUGIN_DIR . 'includes/class-ap2-qm-collector.php';
		$collectors['ap2_queries'] = new AP2_QM_Collector();
		return $collectors;
	}

	/**
	 * Get query statistics.
	 *
	 * @return array
	 */
	public function get_query_stats() {
		return array(
			'cache_hits' => isset( $this->query_cache['hits'] ) ? $this->query_cache['hits'] : 0,
			'analyzed_queries' => count( $this->explain_cache ),
			'optimized_queries' => count( $this->query_cache ),
		);
	}

	/**
	 * Clear query cache.
	 */
	public function clear_cache() {
		wp_cache_delete_group( self::CACHE_GROUP );
		$this->query_cache = array();
		$this->explain_cache = array();
	}
}

// Initialize optimizer.
AP2_Query_Optimizer::instance();