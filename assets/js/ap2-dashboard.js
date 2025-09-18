/**
 * AP2 Dashboard Scripts
 */

(function( $ ) {
	'use strict';

	$( document ).ready( function() {

		// Refresh statistics button
		$( '#ap2-refresh-stats' ).on( 'click', function( e ) {
			e.preventDefault();

			var $button = $( this );
			var $statsCards = $( '.ap2-stats-cards' );

			// Disable button and show loading
			$button.prop( 'disabled', true ).addClass( 'updating-message' );
			$statsCards.addClass( 'ap2-loading' );

			// Make AJAX request
			$.ajax({
				url: ap2_dashboard.ajax_url,
				type: 'POST',
				data: {
					action: 'ap2_refresh_stats',
					nonce: ap2_dashboard.nonce
				},
				success: function( response ) {
					if ( response.success && response.data ) {
						// Update agent orders
						$( '.ap2-stat-card:eq(0) .ap2-stat-number' ).fadeOut( 200, function() {
							$( this ).text( response.data.agent_orders ).fadeIn( 200 );
						});
						$( '.ap2-stat-card:eq(0) .ap2-stat-label' ).fadeOut( 200, function() {
							$( this ).html( response.data.agent_revenue + ' revenue' ).fadeIn( 200 );
						});

						// Update human orders
						$( '.ap2-stat-card:eq(1) .ap2-stat-number' ).fadeOut( 200, function() {
							$( this ).text( response.data.human_orders ).fadeIn( 200 );
						});
						$( '.ap2-stat-card:eq(1) .ap2-stat-label' ).fadeOut( 200, function() {
							$( this ).html( response.data.human_revenue + ' revenue' ).fadeIn( 200 );
						});

						// Update conversion rate
						$( '.ap2-stat-card:eq(2) .ap2-stat-number' ).fadeOut( 200, function() {
							$( this ).text( response.data.conversion ).fadeIn( 200 );
						});

						// Show success message
						showNotice( 'Statistics refreshed successfully!', 'success' );
					} else {
						showNotice( 'Error refreshing statistics.', 'error' );
					}
				},
				error: function() {
					showNotice( 'Failed to refresh statistics. Please try again.', 'error' );
				},
				complete: function() {
					// Re-enable button and remove loading
					$button.prop( 'disabled', false ).removeClass( 'updating-message' );
					$statsCards.removeClass( 'ap2-loading' );
				}
			});
		});

		// Tooltips for chart bars
		$( '.ap2-chart-bar' ).on( 'mouseenter', function() {
			var title = $( this ).attr( 'title' );
			if ( title ) {
				var $tooltip = $( '<div class="ap2-tooltip">' + title + '</div>' );
				$( 'body' ).append( $tooltip );

				var offset = $( this ).offset();
				var width = $( this ).outerWidth();
				var height = $( this ).outerHeight();

				$tooltip.css({
					position: 'absolute',
					top: offset.top - 35,
					left: offset.left + ( width / 2 ) - ( $tooltip.outerWidth() / 2 ),
					background: '#333',
					color: 'white',
					padding: '5px 10px',
					borderRadius: '3px',
					fontSize: '12px',
					zIndex: 9999,
					pointerEvents: 'none'
				});
			}
		}).on( 'mouseleave', function() {
			$( '.ap2-tooltip' ).remove();
		});

		// Auto-refresh statistics every 60 seconds if page is visible
		var autoRefreshInterval;

		function startAutoRefresh() {
			autoRefreshInterval = setInterval( function() {
				if ( document.visibilityState === 'visible' ) {
					$( '#ap2-refresh-stats' ).trigger( 'click' );
				}
			}, 60000 ); // 60 seconds
		}

		function stopAutoRefresh() {
			if ( autoRefreshInterval ) {
				clearInterval( autoRefreshInterval );
			}
		}

		// Handle visibility change
		document.addEventListener( 'visibilitychange', function() {
			if ( document.visibilityState === 'hidden' ) {
				stopAutoRefresh();
			} else {
				startAutoRefresh();
			}
		});

		// Start auto-refresh if enabled
		if ( $( '.ap2-dashboard' ).data( 'auto-refresh' ) !== false ) {
			startAutoRefresh();
		}

		// Order status filter (if implemented)
		$( '.ap2-status-filter' ).on( 'change', function() {
			var status = $( this ).val();
			var $rows = $( '.ap2-recent-orders tbody tr' );

			if ( status === 'all' ) {
				$rows.show();
			} else {
				$rows.hide();
				$rows.filter( '[data-status="' + status + '"]' ).show();
			}
		});

		// Show notification
		function showNotice( message, type ) {
			var $notice = $( '<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>' );
			$( '.ap2-dashboard h1' ).after( $notice );

			// Auto dismiss after 3 seconds
			setTimeout( function() {
				$notice.fadeOut( function() {
					$( this ).remove();
				});
			}, 3000 );

			// Make dismissible
			$notice.on( 'click', '.notice-dismiss', function() {
				$notice.fadeOut( function() {
					$( this ).remove();
				});
			});
		}

		// Animate numbers on page load
		function animateNumbers() {
			$( '.ap2-stat-number' ).each( function() {
				var $this = $( this );
				var text = $this.text();

				// Skip if not a number
				if ( text.includes( '%' ) || text.includes( '$' ) ) {
					return;
				}

				var number = parseInt( text.replace( /,/g, '' ) );
				if ( ! isNaN( number ) ) {
					$this.prop( 'Counter', 0 ).animate({
						Counter: number
					}, {
						duration: 1000,
						easing: 'swing',
						step: function( now ) {
							$this.text( Math.ceil( now ).toLocaleString() );
						}
					});
				}
			});
		}

		// Run animation on page load
		animateNumbers();

		// Print functionality for reports
		$( '.ap2-print-report' ).on( 'click', function( e ) {
			e.preventDefault();
			window.print();
		});

		// Export data (if implemented)
		$( '.ap2-export-data' ).on( 'click', function( e ) {
			e.preventDefault();

			// Collect data
			var data = {
				stats: [],
				orders: []
			};

			// Get statistics
			$( '.ap2-stat-card' ).each( function() {
				data.stats.push({
					label: $( this ).find( 'h3' ).text(),
					value: $( this ).find( '.ap2-stat-number' ).text(),
					detail: $( this ).find( '.ap2-stat-label' ).text()
				});
			});

			// Get recent orders
			$( '.ap2-recent-orders tbody tr' ).each( function() {
				var $cells = $( this ).find( 'td' );
				data.orders.push({
					order: $cells.eq( 0 ).text().trim(),
					date: $cells.eq( 1 ).text().trim(),
					agent: $cells.eq( 2 ).text().trim(),
					status: $cells.eq( 3 ).text().trim(),
					total: $cells.eq( 4 ).text().trim()
				});
			});

			// Create CSV or trigger download
			console.log( 'Export data:', data );
			showNotice( 'Export feature coming soon!', 'info' );
		});

	});

})( jQuery );