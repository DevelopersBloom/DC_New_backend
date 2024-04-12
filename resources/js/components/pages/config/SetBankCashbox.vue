<template>
    <div class="overflow-x-auto overflow-y-visible sm:rounded-lg container mx-auto p-5">
        <span v-if="pageLoading" class="pl-2 page-spinner-handler"><fwb-spinner size="6" color="yellow" /></span>
        <p class="text-gray-600 dark:text-gray-300 text-lg mb-2">Ծրագրի Կարգավորում 2/3</p>
        <div class="bg-white p-4 dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700">
            <p class="text-gray-600 dark:text-gray-300">Ներմուծեք այս պահի դրությամբ բանկային հաշվեհամարի վրա առկա գումարը</p>
            <div class="mt-2">
                <form @submit="setCashbox">
                    <div class="flex gap-3 items-center">
                        <div class="max-w-sm w-full">
                            <fwb-input type="number" v-model="amount" step="0.01"></fwb-input>
                            <p v-if="formErrors.amount" class="text-red-600 text-xs mt-2">
                                Գումարը պետք է լինի դրական
                            </p>
                        </div>
                        <span class="text-gray-600 dark:text-gray-300">{{makeMoney(amount)}}</span>
                    </div>
                    <div class="mt-4" v-if="amount">
                        <fwb-button type="submit">Հաստատել</fwb-button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
<script>
import { ref } from "vue";
import { FwbButton, FwbInput, FwbSpinner } from "flowbite-vue";
import { alertSuccess, makeMoney } from "@/calc";
import getAxios from "@/axios";
import store from "@/store";
import router from "@/router";
export default {
    name: "SetBankCashbox", components: { FwbButton, FwbInput, FwbSpinner },
    setup(){
        const pageLoading = ref(false);
        const amount = ref('');
        const formErrors = ref({});
        const setCashbox = (e) => {
            e.preventDefault();
            if(amount.value && amount.value > 0){
                pageLoading.value = true
                getAxios().post('/api/config/set-bank-cashbox-value',{
                    amount:amount.value
                }).then((res) => {
                    pageLoading.value = false
                    if(res.data.success === 'success'){
                        alertSuccess('Բանկային դրամարկղը հաջողությամբ պահպանված է')
                        store.commit('setUser',res.data.user)
                        router.push('/set-orders')
                    }
                })
            }else{
                formErrors.value.amount = true
            }

        }
        return{
            pageLoading,
            amount,
            makeMoney,
            formErrors,
            setCashbox
        }
    }
}
</script>

<style scoped>

</style>
