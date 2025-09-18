/**
 * AP2 Analytics Page Components
 *
 * WooCommerce Admin integration using native components.
 */

import { __ } from '@wordpress/i18n';
import { Component, Fragment } from '@wordpress/element';
import { compose } from '@wordpress/compose';
import { withSelect, withDispatch } from '@wordpress/data';
import { recordEvent } from '@woocommerce/tracks';
import {
	SummaryList,
	SummaryListPlaceholder,
	SummaryNumber,
	Chart,
	ReportTable,
	TableCard,
	TableSummary,
	CompareFilter,
	FilterPicker,
	AdvancedFilters,
	ReportFilters,
	usePrevious,
} from '@woocommerce/components';
import {
	getDateParamsFromQuery,
	getCurrentDates,
	getIntervalForQuery,
	getChartTypeForQuery,
	getPersistedQuery,
} from '@woocommerce/date';
import { CurrencyContext } from '@woocommerce/currency';
import { REPORTS_STORE_NAME } from '@woocommerce/data';
import { TabPanel, Card, CardHeader, CardBody, CardFooter } from '@wordpress/components';

/**
 * AP2 Agents Analytics Component
 */
class AP2AgentsAnalytics extends Component {
	constructor( props ) {
		super( props );

		this.state = {
			activeTab: 'overview',
		};

		this.tabs = [
			{
				name: 'overview',
				title: __( 'Overview', 'ap2-gateway' ),
				className: 'ap2-analytics-overview',
			},
			{
				name: 'performance',
				title: __( 'Performance', 'ap2-gateway' ),
				className: 'ap2-analytics-performance',
			},
			{
				name: 'orders',
				title: __( 'Agent Orders', 'ap2-gateway' ),
				className: 'ap2-analytics-orders',
			},
		];
	}

	componentDidMount() {
		recordEvent( 'ap2_analytics_view', {
			tab: this.state.activeTab,
		} );
	}

	renderOverviewTab() {
		const { query, path } = this.props;
		const { primary, secondary } = getCurrentDates( query );
		const dateParams = getDateParamsFromQuery( query );

		return (
			<Fragment>
				<SummaryList>
					{ () => (
						<Fragment>
							<SummaryNumber
								key="agent-orders"
								label={ __( 'Agent Orders', 'ap2-gateway' ) }
								value={ this.getAgentOrdersCount() }
								prevValue={ this.getPrevAgentOrdersCount() }
								delta={ this.getAgentOrdersDelta() }
								href={ this.getOrdersLink( 'agent' ) }
							/>
							<SummaryNumber
								key="agent-revenue"
								label={ __( 'Agent Revenue', 'ap2-gateway' ) }
								value={ this.getAgentRevenue() }
								prevValue={ this.getPrevAgentRevenue() }
								delta={ this.getAgentRevenueDelta() }
								currency
							/>
							<SummaryNumber
								key="avg-order-value"
								label={ __( 'Avg Agent Order', 'ap2-gateway' ) }
								value={ this.getAvgOrderValue() }
								prevValue={ this.getPrevAvgOrderValue() }
								delta={ this.getAvgOrderDelta() }
								currency
							/>
							<SummaryNumber
								key="conversion-rate"
								label={ __( 'Agent Share', 'ap2-gateway' ) }
								value={ this.getConversionRate() }
								prevValue={ this.getPrevConversionRate() }
								delta={ this.getConversionDelta() }
								suffix="%"
							/>
						</Fragment>
					) }
				</SummaryList>

				<div className="ap2-top-agents">
					<Card>
						<CardHeader>
							<h2>{ __( 'Top Agents', 'ap2-gateway' ) }</h2>
						</CardHeader>
						<CardBody>
							{ this.renderTopAgentsTable() }
						</CardBody>
					</Card>
				</div>
			</Fragment>
		);
	}

	renderPerformanceTab() {
		const { query } = this.props;
		const chartType = getChartTypeForQuery( query );
		const interval = getIntervalForQuery( query );

		return (
			<Fragment>
				<Chart
					query={ query }
					path="/analytics/ap2-agents"
					chartType={ chartType }
					interval={ interval }
					title={ __( 'Agent Orders Performance', 'ap2-gateway' ) }
					layout="time-comparison"
					type="line"
					allowedIntervals={ [ 'hour', 'day', 'week', 'month', 'quarter', 'year' ] }
					pointLabelFormat={ this.getPointLabelFormat }
					tooltipTitle={ __( 'Agent Performance', 'ap2-gateway' ) }
					xFormat="time"
					yBegin={ 0 }
					chartData={ this.getChartData() }
				/>

				<Chart
					query={ query }
					path="/analytics/ap2-agents"
					chartType="bar"
					interval={ interval }
					title={ __( 'Agent vs Human Orders', 'ap2-gateway' ) }
					layout="comparison"
					type="bar"
					allowedIntervals={ [ 'day', 'week', 'month' ] }
					chartData={ this.getComparisonData() }
				/>
			</Fragment>
		);
	}

	renderOrdersTab() {
		const { query } = this.props;

		const tableQuery = {
			...query,
			paged: query.paged || 1,
			per_page: query.per_page || 25,
		};

		return (
			<ReportTable
				endpoint="ap2-agents"
				getHeadersContent={ this.getHeadersContent }
				getRowsContent={ this.getRowsContent }
				getSummary={ this.getSummary }
				summaryFields={ [ 'agent_orders', 'agent_revenue', 'avg_order_value' ] }
				query={ tableQuery }
				tableQuery={ tableQuery }
				title={ __( 'Agent Orders', 'ap2-gateway' ) }
				columnPrefsKey="ap2_agent_orders"
				filters={ this.getFilters() }
				advancedFilters={ this.getAdvancedFilters() }
			/>
		);
	}

	getHeadersContent() {
		return [
			{
				label: __( 'Order', 'ap2-gateway' ),
				key: 'order_number',
				required: true,
				isLeftAligned: true,
				isSortable: true,
			},
			{
				label: __( 'Date', 'ap2-gateway' ),
				key: 'date',
				defaultSort: true,
				isSortable: true,
			},
			{
				label: __( 'Agent ID', 'ap2-gateway' ),
				key: 'agent_id',
				isSortable: true,
			},
			{
				label: __( 'Status', 'ap2-gateway' ),
				key: 'status',
				isSortable: false,
			},
			{
				label: __( 'Total', 'ap2-gateway' ),
				key: 'total',
				isNumeric: true,
				isSortable: true,
			},
		];
	}

	getRowsContent( data = [] ) {
		const { formatCurrency, getCurrencyConfig } = this.context;

		return data.map( ( row ) => {
			const {
				order_id,
				order_number,
				date_created,
				agent_id,
				status,
				total,
			} = row;

			return [
				{
					display: (
						<a href={ `admin.php?page=wc-orders&action=edit&id=${ order_id }` }>
							#{ order_number }
						</a>
					),
					value: order_number,
				},
				{
					display: date_created,
					value: date_created,
				},
				{
					display: agent_id || '-',
					value: agent_id,
				},
				{
					display: (
						<span className={ `order-status status-${ status }` }>
							{ status }
						</span>
					),
					value: status,
				},
				{
					display: formatCurrency( total ),
					value: total,
				},
			];
		} );
	}

	getSummary( totals ) {
		const {
			agent_orders = 0,
			agent_revenue = 0,
			avg_order_value = 0,
		} = totals;

		return [
			{
				label: __( 'Total Orders', 'ap2-gateway' ),
				value: agent_orders,
			},
			{
				label: __( 'Total Revenue', 'ap2-gateway' ),
				value: formatCurrency( agent_revenue ),
			},
			{
				label: __( 'Average Order', 'ap2-gateway' ),
				value: formatCurrency( avg_order_value ),
			},
		];
	}

	getFilters() {
		return [
			{
				label: __( 'Show', 'ap2-gateway' ),
				staticParams: [],
				param: 'filter',
				showFilters: () => true,
				defaultValue: 'all',
				filters: [
					{
						label: __( 'All Agent Orders', 'ap2-gateway' ),
						value: 'all',
					},
					{
						label: __( 'Test Orders', 'ap2-gateway' ),
						value: 'test',
					},
					{
						label: __( 'Live Orders', 'ap2-gateway' ),
						value: 'live',
					},
				],
			},
		];
	}

	getAdvancedFilters() {
		return {
			filters: {
				agent_id: {
					labels: {
						add: __( 'Agent ID', 'ap2-gateway' ),
						placeholder: __( 'Search agents', 'ap2-gateway' ),
						remove: __( 'Remove agent filter', 'ap2-gateway' ),
						rule: __( 'Select an agent', 'ap2-gateway' ),
						title: __( 'Agent ID', 'ap2-gateway' ),
					},
					rules: [
						{
							value: 'includes',
							label: __( 'Includes', 'ap2-gateway' ),
						},
						{
							value: 'excludes',
							label: __( 'Excludes', 'ap2-gateway' ),
						},
					],
					input: {
						component: 'Search',
						type: 'agents',
						getLabels: this.getAgentLabels,
					},
				},
			},
		};
	}

	// Placeholder data methods - these would connect to real data in production
	getAgentOrdersCount() {
		return 0;
	}

	getPrevAgentOrdersCount() {
		return 0;
	}

	getAgentOrdersDelta() {
		return 0;
	}

	getAgentRevenue() {
		return 0;
	}

	getPrevAgentRevenue() {
		return 0;
	}

	getAgentRevenueDelta() {
		return 0;
	}

	getAvgOrderValue() {
		return 0;
	}

	getPrevAvgOrderValue() {
		return 0;
	}

	getAvgOrderDelta() {
		return 0;
	}

	getConversionRate() {
		return 0;
	}

	getPrevConversionRate() {
		return 0;
	}

	getConversionDelta() {
		return 0;
	}

	getOrdersLink( type ) {
		return `admin.php?page=wc-orders&order_type=${ type }`;
	}

	renderTopAgentsTable() {
		// This would be populated with real data
		return (
			<TableCard
				title={ __( 'Top Performing Agents', 'ap2-gateway' ) }
				headers={ [
					{ key: 'agent_id', label: __( 'Agent ID', 'ap2-gateway' ) },
					{ key: 'orders', label: __( 'Orders', 'ap2-gateway' ), isNumeric: true },
					{ key: 'revenue', label: __( 'Revenue', 'ap2-gateway' ), isNumeric: true },
				] }
				rows={ [] }
				rowsPerPage={ 10 }
				totalRows={ 0 }
			/>
		);
	}

	getChartData() {
		// This would return real chart data
		return [];
	}

	getComparisonData() {
		// This would return real comparison data
		return [];
	}

	getPointLabelFormat( point, startOfPeriod ) {
		return '';
	}

	getAgentLabels( search ) {
		// This would search for agent labels
		return Promise.resolve( [] );
	}

	render() {
		const { query, path } = this.props;

		return (
			<Fragment>
				<ReportFilters
					query={ query }
					path={ path }
					dateFilter={ true }
					showDatePicker={ true }
					filters={ [] }
					advancedFilters={ {} }
					report="ap2-agents"
				/>

				<TabPanel
					className="ap2-analytics-tabs"
					activeClass="is-active"
					tabs={ this.tabs }
					onSelect={ ( tabName ) => {
						recordEvent( 'ap2_analytics_tab_click', {
							tab: tabName,
						} );
						this.setState( { activeTab: tabName } );
					} }
				>
					{ ( tab ) => {
						switch ( tab.name ) {
							case 'overview':
								return this.renderOverviewTab();
							case 'performance':
								return this.renderPerformanceTab();
							case 'orders':
								return this.renderOrdersTab();
							default:
								return null;
						}
					} }
				</TabPanel>
			</Fragment>
		);
	}
}

AP2AgentsAnalytics.contextType = CurrencyContext;

export default compose(
	withSelect( ( select, props ) => {
		const { query } = props;
		const { getReportItems, getReportItemsError, isReportItemsRequesting } = select( REPORTS_STORE_NAME );

		const tableQuery = {
			...query,
			per_page: query.per_page || 25,
			paged: query.paged || 1,
		};

		const reportItems = getReportItems( 'ap2-agents', tableQuery );
		const isError = Boolean( getReportItemsError( 'ap2-agents', tableQuery ) );
		const isRequesting = isReportItemsRequesting( 'ap2-agents', tableQuery );

		return {
			reportItems,
			isError,
			isRequesting,
		};
	} )
)( AP2AgentsAnalytics );