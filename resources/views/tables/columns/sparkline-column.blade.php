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
                stroke: {
                    curve: 'smooth',
                    width: 2
                },
                tooltip: {
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
                    }
                },
                colors: ['#3b82f6']
            };

            const chart = new ApexCharts(document.querySelector("#chart-{{ $getRecord()->id }}"), options);
            chart.render();
        });
    </script>
</div>
