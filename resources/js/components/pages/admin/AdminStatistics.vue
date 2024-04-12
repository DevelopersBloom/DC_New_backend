<template>
    <div class="overflow-x-auto overflow-y-visible sm:rounded-lg container mx-auto pt-10 p-5">
        <apexchart ref="chartRef" type="pie" width="380" :options="chart.chartOptions" :series="chart.series"></apexchart>
    </div>
</template>

<script>
import { reactive, ref } from "vue";
import getAxios from "@/axios";

export default {
    name: "AdminStatistics",
    components: { },
    setup() {
        const contract = ref({});
        const chartRef = ref()
        const chart = reactive({
            series: [{
                data: []
            }],
            chartOptions: {
                chart: {
                    type: 'bar',
                    height: 250,
                    width: '50%'
                },
                plotOptions: {
                    bar: {
                        barHeight: '100%',
                        distributed: true,
                        horizontal: true,
                        dataLabels: {
                            enabled: true,
                            textAnchor: 'start',
                            position: 'bottom'
                        },
                    }
                },
                colors: ['#33b2df', '#546E7A', '#d4526e', '#13d8aa', '#A5978B', '#2b908f', '#f9a3a4', '#90ee7e',
                    '#f48024', '#69d2e7'
                ],
                dataLabels: {
                    enabled: true,
                    textAnchor: 'start',
                    style: {
                        colors: ['#fff']
                    },
                    formatter: function (val, opt) {
                        return opt.w.globals.labels[opt.dataPointIndex] + ":  " + val
                    },
                    offsetX: 0,
                    dropShadow: {
                        enabled: true
                    }
                },
                stroke: {
                    width: 1,
                    colors: ['#fff']
                },
                xaxis: {
                    categories: [],
                },
                yaxis: {
                    labels: {
                        show: false
                    }
                },
                legend: {
                    show: false
                },
                title: {
                    text: 'Պայմանագրեր',
                    align: 'center',
                    floating: true
                },
                subtitle: {
                    text: 'Կատարված Պայմանագրերի քանակը ըստ օգտատերերի',
                    align: 'center',
                },
                tooltip: {
                    enabled:false,
                    theme: 'dark',
                    x: {
                        show: false
                    },
                    y: {
                        title: {
                            formatter: function () {
                                return ''
                            }
                        }
                    }
                }
            }
        })
        getAxios().get('/api/admin/get-users').then((res) => {
            let users = res.data.users;
            let series = [];
            let labels = [];
            users.forEach((user) => {
                series.push(user.contracts_count)
                labels.push(user.name + ' ' + user.surname)
            })
            chart.chartOptions.chart.height = series.length * 50
            chart.series[0] = {data: series}
            chart.chartOptions.xaxis.categories = labels
            chartRef.value.updateOptions(chart.chartOptions)
        })
        return {
            contract,
            chartRef,
            chart,
        }
    }
}
</script>


<style scoped>

</style>
