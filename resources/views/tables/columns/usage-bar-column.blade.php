<div>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

    <div id="usage-chart-{{ $getRecord()->id }}" style="min-height: 40px;"></div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const data = @json($getState());
            
            if (!data) return;

            const value = parseFloat(data.value);
            let color = '#22c55e'; // green
            
            if (value >= 80) {
                color = '#ef4444'; // red
            } else if (value >= 70) {
                color = '#f97316'; // orange
            }

            const options = {
                series: [{
                    data: [value]
                }],
                chart: {
                    type: 'bar',
                    height: 40,
                    toolbar: {
                        show: false
                    },
                    sparkline: {
                        enabled: false
                    }
                },
                plotOptions: {
                    bar: {
                        horizontal: true,
                        distributed: true,
                        borderRadius: 4,
                        columnWidth: '70%',
                        barHeight: '70%',
                        dataLabels: {
                            position: 'center'
                        }
                    }
                },
                dataLabels: {
                    enabled: true,
                    formatter: function(val) {
                        return Math.round(val) + '%';
                    },
                    style: {
                        fontSize: '12px',
                        fontWeight: 600,
                        colors: ['#fff']
                    }
                },
                xaxis: {
                    categories: [data.label],
                    labels: {
                        show: false
                    },
                    axisBorder: {
                        show: false
                    },
                    axisTicks: {
                        show: false
                    },
                    max: 100
                },
                yaxis: {
                    labels: {
                        show: false
                    }
                },
                grid: {
                    show: false,
                    padding: {
                        left: 0,
                        right: 0,
                        top: -15,
                        bottom: -15
                    }
                },
                tooltip: {
                    theme: 'dark',
                    x: {
                        show: false
                    },
                    y: {
                        formatter: function(val) {
                            return data.tooltip || `${Math.round(val)}% Used`;
                        }
                    }
                },
                colors: [color]
            };

            const chart = new ApexCharts(document.querySelector("#usage-chart-{{ $getRecord()->id }}"), options);
            chart.render();
        });
    </script>
</div> 