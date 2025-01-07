<div>

    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

    <div id="chart-{{ $getRecord()->id }}" style="height: 50px;"></div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const data = @json($getState());

            if (!data.length) return;

            const options = {
                series: [{
                    name: 'Response Time',
                    data: data.map(item => item.value)
                }],
                chart: {
                    type: 'line',
                    height: 50,
                    sparkline: {
                        enabled: true
                    },
                    animations: {
                        enabled: false
                    }
                },
                grid: {
                    show: false
                },
                xaxis: {
                    labels: {
                        show: false
                    },
                    axisBorder: {
                        show: false
                    },
                    axisTicks: {
                        show: false
                    }
                },
                yaxis: {
                    min: 0,
                    labels: {
                        show: true,
                        style: {
                            colors: '#9ca3af',
                            fontSize: '10px'
                        },
                        formatter: (value) => `${Math.round(value)}ms`
                    }
                },
                stroke: {
                    curve: 'smooth',
                    width: 2
                },
                tooltip: {
                    theme: 'dark',
                    fixed: {
                        enabled: true
                    },
                    x: {
                        show: true,
                        formatter: function(value, opts) {
                            return data[opts.dataPointIndex].date;
                        }
                    },
                    y: {
                        formatter: function(value) {
                            return value + 'ms';
                        }
                    },
                    style: {
                        fontSize: '12px',
                        fontFamily: undefined
                    },
                    background: {
                        enabled: true,
                        foreColor: '#fff',
                        borderColor: '#1f2937',
                        borderRadius: 6,
                        opacity: 0.9,
                    },
                },
                colors: ['#3b82f6'],
                markers: {
                    size: 0,
                    colors: ['#3b82f6'],
                    strokeColors: '#3b82f6',
                    strokeWidth: 2,
                    hover: {
                        size: 3
                    }
                }
            };

            const chart = new ApexCharts(document.querySelector("#chart-{{ $getRecord()->id }}"), options);
            chart.render();
        });
    </script>
</div>
