<template>
    <div class="overflow-x-auto overflow-y-visible sm:rounded-lg container mx-auto p-5">
        <span v-if="pageLoading" class="pl-2 page-spinner-handler"><fwb-spinner size="6" color="yellow" /></span>
        <p class="text-gray-600 dark:text-gray-300 text-lg mb-2">Ծրագրի Կարգավորում 1/3</p>
        <div class="bg-white p-4 dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700">
            <div class="flex justify-between">
                <div class="w-max">
                    <label class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">Ամիս</label>
                    <fwb-select
                        v-model="month"
                        :options="months">
                    </fwb-select>
                </div>
                <div class="max-w-lg">
                    <span class="text-gray-600 dark:text-gray-300 text-xs">
                        Ուշադրություն! Սեղմել այս կոճակը միայն եթե համոզված եք դրամարկղերի բոլոր ամիսների բոլոր օրերի համապատասխանության մեջ
                    </span>
                    <fwb-button size="sm" class="ml-2" @click="saveCashboxesInDeals">Հաստատել</fwb-button>
                </div>
            </div>
            <div class="text-orange-400 dark:text-orange-400 mt-4 text-sm border border-orange-400 rounded-lg p-4">
                Ուշադրություն! Ստուգել և փոփոխել դրամարկղերը միմիայն ըստ հերթականության։ Եթե դրամարկղը չի համապատասխանում տվյալ
                օրվա դրամարկղին, սեղմել փոփոխել կոճակը, ներմուծել ճիշտ թիվը , այնուհետև սեղմել պահպանել կոճակը
            </div>
            <div v-for="(cashbox,index) in cashboxes" key="index">
                <div class="border rounded-lg border-gray-200 dark:border-gray-500 mt-4 p-3">
                    <div class="flex gap-4 items-center">
                        <span class="text-gray-900 dark:text-white">{{cashbox.date}}</span>
                        <fwb-input :disabled="editing !== index" :id="'cashbox_input_' + index" type="number" v-model="cashbox.changed_amount" />
                        <template v-if="editing === -1 || editing === index">
                            <fwb-button v-if="editing !== index" color="yellow" @click="() => editCashbox(index)"><i class="fa fa-edit" > Փոփոխել</i></fwb-button>
                            <fwb-button v-else @click="() => saveCashbox(index)"><i class="fa fa-save" @click="() => editCashbox(index)"></i> Պահպանել</fwb-button>
                        </template>
                        <span class="text-gray-900 dark:text-white">{{makeMoney(cashbox.changed_amount)}}</span>
                    </div>
                </div>
            </div>
            <div class="mt-4" v-if="hasNextMonth">
                <fwb-button @click="nextMonth">Հաջորդ ամիսը</fwb-button>
            </div>
        </div>
    </div>
</template>

<script>
import { computed, nextTick, reactive, ref, watch } from "vue";
import getAxios from "@/axios";
import { FwbButton, FwbInput, FwbSelect, FwbSpinner } from "flowbite-vue";
import store from "@/store";
import { alertSuccess, makeMoney } from "@/calc";
import router from "@/router";
export default {
    name: "DealsFix",
    components: { FwbSpinner, FwbInput, FwbButton, FwbSelect },
    setup() {
        const pageLoading = ref(false);
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
        watch(() => month.value,() => {
            getList().then((res) => {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            })
        })
        const cashboxes = ref([])
        const editing = ref(-1);
        const months = computed(() => {
            let currentMonth = new Date().getMonth()
            return allMonths.filter(v => v.index <= currentMonth)
        })
        const editCashbox = (index) => {
            editing.value = index
            nextTick(() => {
                document.querySelector('input#cashbox_input_'+ index).focus()
            })
        }
        const nextMonth = () => {
            let current_month = parseInt(month.value);
            current_month++;
            let next_month = current_month < 10 ? '0'+ current_month : '' + current_month
            month.value = next_month
        }
        const saveCashboxesInDeals = () => {
            pageLoading.value = true;
            getAxios().post('/api/config/calculate-cashboxes-final',{}).then((res) =>{
                pageLoading.value = false;
                if(res.data.success === 'success'){
                    alertSuccess('Դրամարկղերը հաջողությամբ հաշվված են')
                    store.commit('setUser',res.data.user)
                    router.push('/set-bank-cashbox')
                }
            })
        }
        const hasNextMonth = computed(() => {
            return parseInt(month.value) < new Date().getMonth() + 1
        })
        const saveCashbox = (index) => {
            const cashbox = cashboxes.value[index]
            pageLoading.value = true;
            editing.value = -1;
            getAxios().post('/api/config/set-cashbox-value',{
                month:month.value,
                amount:cashbox.amount,
                changed_amount:cashbox.changed_amount,
                date:cashbox.date,
            }).then((res) =>{
                cashboxes.value = [];
                pageLoading.value = false;
                res.data.cashboxes.forEach(v => {
                    cashboxes.value.push({
                        amount:v.amount,
                        date:v.date,
                        changed_amount:v.amount
                    })
                })

            })
        }
        const getList = () => {
            pageLoading.value = true;
            return getAxios().post('/api/config/get-cashbox-list',{
                month:month.value
            }).then((res) =>{
                pageLoading.value = false
                cashboxes.value = [];
                res.data.cashboxes.forEach(v => {
                    cashboxes.value.push({
                        amount:v.amount,
                        date:v.date,
                        changed_amount:v.amount
                    })
                })

            })
        }
        getList()
        const user = computed(() => store.state.user)
        return {
            month,
            months,
            cashboxes,
            editCashbox,
            saveCashbox,
            makeMoney,
            editing,
            pageLoading,
            saveCashboxesInDeals,
            hasNextMonth,
            nextMonth
        }
    }
}
</script>


<style scoped>

</style>
