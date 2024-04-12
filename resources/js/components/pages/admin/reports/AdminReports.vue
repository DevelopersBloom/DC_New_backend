<template>
    <div class="overflow-x-auto overflow-y-visible sm:rounded-lg container mx-auto pt-10 p-5">
        <div class="bg-white p-4 dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700">
            <div class="mb-4 w-max">
                <label for="city" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">Ամիս</label>
                <fwb-select
                    v-model="month"
                    :options="months">
                </fwb-select>
            </div>
            <div class="mb-4 w-max">
                <label for="city" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">Տարի</label>
                <fwb-select
                    v-model="year"
                    :options="years">
                </fwb-select>
            </div>
            <div class="mt-8">
                <fwb-button @click="printReport">Տպել</fwb-button>
            </div>
        </div>
    </div>
</template>

<script>
import { computed, reactive, ref } from "vue";
import getAxios from "@/axios";
import { makeMoney } from "../../../../calc";
import { FwbButton, FwbSelect } from "flowbite-vue";
import store from "@/store";

export default {
    name: "AdminReports", methods: { makeMoney },
    components: { FwbButton, FwbSelect },
    setup() {
        const month = ref('01')
        const allMonths = [
            {index: 0, value: '01', name: 'Հունվար' },
            {index: 1, value: '02', name: 'Փետրվար' },
            {index: 2, value: '03', name: 'Մարտ' },
            {index: 3, value: '04', name: 'Ապրիլ' },
            {index: 4, value: '05', name: 'Մայիս' },
            {index: 5, value: '06', name: 'Հունիս' },
            {index: 6, value: '07', name: 'Հուլիս' },
            {index: 7, value: '08', name: 'Օգոստոս' },
            {index: 8, value: '09', name: 'Սեպտեմբեր' },
            {index: 9, value: '10', name: 'Հոկտեմբեր' },
            {index: 10, value: '11', name: 'Նոյեմբեր' },
            {index: 11, value: '12', name: 'Դեկտեմբեր' },
        ]
        const months = computed(() => {

            if(parseInt(year.value) === new Date().getFullYear()){
                let currentMonth = new Date().getMonth()
                return allMonths.filter(v => v.index < currentMonth)
            }
            return allMonths
        })
        const user = computed(() => store.state.user)
        const year = ref(new Date().getFullYear() + '')
        const years = []
        for(let i = 2024; i <= new Date().getFullYear(); i++){
            let val = i + ''
            years.push(
                { value: val, name: val }
            )
        }
        function getLastDayOfMonth(dateString) {
            const [month, year] = dateString.split('.').map(Number);
            const nextMonthFirstDay = new Date(year, month, 1);
            const lastDayOfMonth = new Date(nextMonthFirstDay - 1);
            return`${lastDayOfMonth.getDate()}.${month}.${year}`;
        }
        const printReport = () => {
            getAxios().post('/api/download-monthly-export',{
                year:year.value,
                month:month.value,
                pawnshop_id:user.value?.pawnshop?.id
            },{ responseType: 'blob' })
            .then(response => {
                const url = window.URL.createObjectURL(new Blob([response.data]));
                const link = document.createElement('a');
                link.href = url;
                let dateString = month.value + '.' + year.value
                let filename = getLastDayOfMonth(dateString) + ' ամսական.xlsx'
                link.setAttribute('download', filename);
                document.body.appendChild(link);
                link.click();
                link.remove()
            })
        }
        return {
            month,
            months,
            year,
            years,
            printReport
        }
    }
}
</script>


<style scoped>

</style>
