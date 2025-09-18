/**
 * AP2 Analytics Page
 *
 * Renders the AP2 Agent Analytics page in WooCommerce Admin.
 */

(function(wp, wc) {
    const { __ } = wp.i18n;
    const { Component, Fragment } = wp.element;
    const { Card, CardBody, CardHeader } = wp.components;
    const { SummaryList, SummaryNumber, Chart } = wc.components;

    class AP2AgentAnalytics extends Component {
        constructor() {
            super();
            this.state = {
                data: null,
                loading: true,
                error: null,
                dateRange: 'week'
            };
        }

        componentDidMount() {
            this.fetchData();
        }

        fetchData() {
            const { dateRange } = this.state;

            fetch(`${ap2Analytics.apiUrl}analytics/agents?period=${dateRange}`, {
                headers: {
                    'X-WP-Nonce': ap2Analytics.nonce
                }
            })
            .then(response => response.json())
            .then(data => {
                this.setState({ data, loading: false });
            })
            .catch(error => {
                this.setState({ error: error.message, loading: false });
            });
        }

        render() {
            const { data, loading, error } = this.state;

            if (loading) {
                return <div className="ap2-loading">Loading analytics data...</div>;
            }

            if (error) {
                return <div className="ap2-error">Error loading data: {error}</div>;
            }

            if (!data) {
                return null;
            }

            const { summary, top_agents, time_series } = data;

            // Prepare chart data
            const chartData = time_series ? time_series.map(row => ({
                date: row.order_date,
                'Agent Orders': parseInt(row.agent_orders),
                'Human Orders': parseInt(row.human_orders),
                'Agent Revenue': parseFloat(row.agent_revenue),
                'Human Revenue': parseFloat(row.human_revenue)
            })) : [];

            return (
                <Fragment>
                    <div className="ap2-analytics-header">
                        <h1>🤖 {__('AP2 Agent Analytics', 'ap2-gateway')}</h1>
                    </div>

                    <SummaryList>
                        <SummaryNumber
                            value={summary.agent_orders}
                            label={__('Agent Orders', 'ap2-gateway')}
                            delta={summary.conversion_rate}
                            deltaType="percentage"
                        />
                        <SummaryNumber
                            value={`${ap2Analytics.currency}${summary.agent_revenue.toFixed(2)}`}
                            label={__('Agent Revenue', 'ap2-gateway')}
                        />
                        <SummaryNumber
                            value={`${ap2Analytics.currency}${summary.avg_agent_order.toFixed(2)}`}
                            label={__('Avg Agent Order', 'ap2-gateway')}
                        />
                        <SummaryNumber
                            value={`${summary.conversion_rate}%`}
                            label={__('Agent Conversion', 'ap2-gateway')}
                        />
                    </SummaryList>

                    {chartData.length > 0 && (
                        <Card className="ap2-chart-card">
                            <CardHeader>
                                <h2>{__('Orders Over Time', 'ap2-gateway')}</h2>
                            </CardHeader>
                            <CardBody>
                                <Chart
                                    data={chartData}
                                    title={__('Agent vs Human Orders', 'ap2-gateway')}
                                    layout="time-comparison"
                                    type="line"
                                />
                            </CardBody>
                        </Card>
                    )}

                    {top_agents && top_agents.length > 0 && (
                        <Card className="ap2-top-agents-card">
                            <CardHeader>
                                <h2>{__('Top Agents', 'ap2-gateway')}</h2>
                            </CardHeader>
                            <CardBody>
                                <table className="wp-list-table widefat fixed striped">
                                    <thead>
                                        <tr>
                                            <th>{__('Agent ID', 'ap2-gateway')}</th>
                                            <th>{__('Orders', 'ap2-gateway')}</th>
                                            <th>{__('Revenue', 'ap2-gateway')}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {top_agents.map((agent, index) => (
                                            <tr key={index}>
                                                <td>{agent.agent_id}</td>
                                                <td>{agent.order_count}</td>
                                                <td>{ap2Analytics.currency}{parseFloat(agent.total_revenue).toFixed(2)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </CardBody>
                        </Card>
                    )}
                </Fragment>
            );
        }
    }

    // Mount the component when the page is ready
    document.addEventListener('DOMContentLoaded', function() {
        const rootElement = document.getElementById('ap2-analytics-root');
        if (rootElement) {
            wp.element.render(
                <AP2AgentAnalytics />,
                rootElement
            );
        }
    });

})(window.wp, window.wc);