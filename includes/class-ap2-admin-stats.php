<?php
/**
 * AP2 Admin Statistics Dashboard
 *
 * Provides admin dashboard widget for agent statistics.
 *
 * @package AP2Gateway
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AP2 Admin Statistics Class.
 */
class AP2_Admin_Stats {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widget' ) );
		add_action( 'admin_menu', array( $this, 'add_stats_submenu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
	}

	/**
	 * Add dashboard widget.
	 */
	public function add_dashboard_widget() {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			wp_add_dashboard_widget(
				'ap2_agent_stats',
				__( '🤖 AP2 Agent Statistics', 'ap2-gateway' ),
				array( $this, 'render_dashboard_widget' )
			);
		}
	}

	/**
	 * Add statistics submenu page.
	 */
	public function add_stats_submenu() {
		add_submenu_page(
			'woocommerce',
			__( 'AP2 Agent Statistics', 'ap2-gateway' ),
			__( 'AP2 Statistics', 'ap2-gateway' ),
			'manage_woocommerce',
			'ap2-agent-stats',
			array( $this, 'render_stats_page' )
		);
	}

	/**
	 * Enqueue admin styles.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_styles( $hook ) {
		if ( 'index.php' === $hook || 'woocommerce_page_ap2-agent-stats' === $hook ) {
			wp_enqueue_style(
				'ap2-admin-styles',
				AP2_GATEWAY_PLUGIN_URL . 'assets/css/ap2-admin.css',
				array(),
				AP2_GATEWAY_VERSION,
				'all'
			);
		}
	}

	/**
	 * Render dashboard widget.
	 */
	public function render_dashboard_widget() {
		$stats = AP2_Agent_Detector::get_formatted_statistics();
		?>
		<div class="ap2-stats-widget">
			<div class="ap2-stats-row">
				<div class="ap2-stat-item">
					<span class="ap2-stat-label"><?php esc_html_e( 'Total Visits', 'ap2-gateway' ); ?></span>
					<span class="ap2-stat-value"><?php echo esc_html( $stats['total_visits'] ); ?></span>
				</div>
				<div class="ap2-stat-item">
					<span class="ap2-stat-label"><?php esc_html_e( 'Unique Agents', 'ap2-gateway' ); ?></span>
					<span class="ap2-stat-value"><?php echo esc_html( $stats['unique_agents'] ); ?></span>
				</div>
			</div>
			<div class="ap2-stats-row">
				<div class="ap2-stat-item">
					<span class="ap2-stat-label"><?php esc_html_e( 'Visits Today', 'ap2-gateway' ); ?></span>
					<span class="ap2-stat-value"><?php echo esc_html( $stats['visits_today'] ); ?></span>
				</div>
				<div class="ap2-stat-item">
					<span class="ap2-stat-label"><?php esc_html_e( 'Last 24 Hours', 'ap2-gateway' ); ?></span>
					<span class="ap2-stat-value"><?php echo esc_html( $stats['visits_24h'] ); ?></span>
				</div>
			</div>
			<div class="ap2-stat-footer">
				<span class="ap2-stat-label"><?php esc_html_e( 'Last Visit:', 'ap2-gateway' ); ?></span>
				<span><?php echo esc_html( $stats['last_visit'] ); ?></span>
			</div>
			<p class="ap2-stats-link">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=ap2-agent-stats' ) ); ?>" class="button">
					<?php esc_html_e( 'View Detailed Statistics', 'ap2-gateway' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render statistics page.
	 */
	public function render_stats_page() {
		$stats = AP2_Agent_Detector::get_statistics();
		$formatted_stats = AP2_Agent_Detector::get_formatted_statistics();

		// Handle clear statistics action.
		if ( isset( $_POST['clear_stats'] ) && check_admin_referer( 'ap2_clear_stats' ) ) {
			AP2_Agent_Detector::clear_statistics();
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Statistics cleared successfully.', 'ap2-gateway' ) . '</p></div>';
			$stats = AP2_Agent_Detector::get_statistics();
			$formatted_stats = AP2_Agent_Detector::get_formatted_statistics();
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AP2 Agent Statistics', 'ap2-gateway' ); ?></h1>

			<div class="ap2-stats-grid">
				<!-- Summary Cards -->
				<div class="ap2-stats-card">
					<h2><?php esc_html_e( 'Overview', 'ap2-gateway' ); ?></h2>
					<table class="ap2-stats-table">
						<tr>
							<th><?php esc_html_e( 'Total Agent Visits', 'ap2-gateway' ); ?></th>
							<td><?php echo esc_html( $formatted_stats['total_visits'] ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Unique Agents', 'ap2-gateway' ); ?></th>
							<td><?php echo esc_html( $formatted_stats['unique_agents'] ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Visits Today', 'ap2-gateway' ); ?></th>
							<td><?php echo esc_html( $formatted_stats['visits_today'] ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Last 24 Hours', 'ap2-gateway' ); ?></th>
							<td><?php echo esc_html( $formatted_stats['visits_24h'] ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Last Visit', 'ap2-gateway' ); ?></th>
							<td><?php echo esc_html( $formatted_stats['last_visit'] ); ?></td>
						</tr>
					</table>
				</div>

				<!-- Top Pages -->
				<div class="ap2-stats-card">
					<h2><?php esc_html_e( 'Top Pages Visited', 'ap2-gateway' ); ?></h2>
					<?php if ( ! empty( $formatted_stats['top_pages'] ) ) : ?>
						<table class="ap2-stats-table">
							<?php foreach ( $formatted_stats['top_pages'] as $page => $count ) : ?>
								<tr>
									<td><?php echo esc_html( $page ); ?></td>
									<td><?php echo esc_html( $count ); ?> <?php esc_html_e( 'visits', 'ap2-gateway' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</table>
					<?php else : ?>
						<p><?php esc_html_e( 'No page views recorded yet.', 'ap2-gateway' ); ?></p>
					<?php endif; ?>
				</div>

				<!-- Daily Visits Chart -->
				<div class="ap2-stats-card ap2-stats-full-width">
					<h2><?php esc_html_e( 'Daily Visits (Last 30 Days)', 'ap2-gateway' ); ?></h2>
					<?php if ( ! empty( $stats['daily_visits'] ) ) : ?>
						<div class="ap2-chart-container">
							<?php
							$max_visits = max( $stats['daily_visits'] );
							foreach ( $stats['daily_visits'] as $date => $count ) :
								$height = $max_visits > 0 ? ( $count / $max_visits * 100 ) : 0;
								?>
								<div class="ap2-chart-bar" style="height: <?php echo esc_attr( $height ); ?>%;" title="<?php echo esc_attr( $date . ': ' . $count . ' visits' ); ?>">
									<span class="ap2-chart-value"><?php echo esc_html( $count ); ?></span>
								</div>
							<?php endforeach; ?>
						</div>
					<?php else : ?>
						<p><?php esc_html_e( 'No visit data available yet.', 'ap2-gateway' ); ?></p>
					<?php endif; ?>
				</div>

				<!-- Unique Agents List -->
				<?php if ( ! empty( $stats['unique_agents'] ) ) : ?>
					<div class="ap2-stats-card">
						<h2><?php esc_html_e( 'Recent Unique Agents', 'ap2-gateway' ); ?></h2>
						<ul class="ap2-agent-list">
							<?php
							$recent_agents = array_slice( $stats['unique_agents'], -10, 10, true );
							foreach ( $recent_agents as $agent ) :
								?>
								<li><?php echo esc_html( $agent ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
			</div>

			<!-- Clear Statistics -->
			<div class="ap2-stats-actions">
				<form method="post" style="display: inline;">
					<?php wp_nonce_field( 'ap2_clear_stats' ); ?>
					<input type="submit" name="clear_stats" class="button button-secondary" value="<?php esc_attr_e( 'Clear All Statistics', 'ap2-gateway' ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to clear all statistics?', 'ap2-gateway' ); ?>');" />
				</form>
			</div>

			<!-- Detection Methods Info -->
			<div class="ap2-info-box">
				<h3><?php esc_html_e( 'Agent Detection Methods', 'ap2-gateway' ); ?></h3>
				<p><?php esc_html_e( 'Agents are detected using the following methods:', 'ap2-gateway' ); ?></p>
				<ul>
					<li><code>X-AP2-Agent</code> <?php esc_html_e( 'HTTP header', 'ap2-gateway' ); ?></li>
					<li><code>User-Agent</code> <?php esc_html_e( 'containing "AP2-Agent"', 'ap2-gateway' ); ?></li>
					<li><code>?ap2_agent=true</code> <?php esc_html_e( 'query parameter', 'ap2-gateway' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}
}

// Initialize admin stats.
new AP2_Admin_Stats();