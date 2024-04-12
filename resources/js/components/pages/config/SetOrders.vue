<template>
    <div class="overflow-x-auto overflow-y-visible sm:rounded-lg container mx-auto p-5">
        <span v-if="pageLoading" class="pl-2 page-spinner-handler"><fwb-spinner size="6" color="yellow" /></span>
        <p class="text-gray-600 dark:text-gray-300 text-lg mb-2">Ծրագրի Կարգավորում 3/3</p>
        <div class="bg-white p-4 dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700">
            <p class="text-gray-600 dark:text-gray-300">Ներմուծեք այս պահի դրությամբ վերջին կանխիկ և անկանխիկ օրդերները</p>
            <div class="mt-2">
                <form @submit="setOrders">
                    <p class="text-orange-400 mt-4">Մուտքի</p>
                    <div class="flex gap-3 items-center mt-4">
                        <div class="max-w-sm w-full">
                            <span class="text-gray-600 dark:text-gray-300">Կանխիկ</span>
                            <fwb-input type="number" v-model="orderIn"></fwb-input>
                        </div>
                        <div class="max-w-sm w-full">
                            <span class="text-gray-600 dark:text-gray-300">Անկանխիկ (Առանց Ա տառի)</span>
                            <fwb-input type="number" v-model="bankOrderIn"></fwb-input>
                        </div>
                    </div>
                    <p class="text-orange-400 mt-4">Ելքի</p>
                    <div class="flex gap-3 items-center mt-4">
                        <div class="max-w-sm w-full">
                            <span class="text-gray-600 dark:text-gray-300">Կանխիկ</span>
                            <fwb-input type="number" v-model="orderOut"></fwb-input>
                        </div>
                        <div class="max-w-sm w-full">
                            <span class="text-gray-600 dark:text-gray-300">Անկանխիկ (Առանց Ա տառի)</span>
                            <fwb-input type="number" v-model="bankOrderOut"></fwb-input>
                        </div>
                    </div>
                    <div class="mt-4">
                        <fwb-button :disabled="!orderIn || !bankOrderIn || !orderOut || !bankOrderOut" type="submit">Հաստատել</fwb-button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
<script>
import { ref } from "vue";
import { FwbButton, FwbInput, FwbSpinner } from "flowbite-vue";
import { alertSuccess, makeMoney } from "../../../calc";
import getAxios from "@/axios";
import store from "@/store";
import router from "@/router";

export default {
    name: "SetOrders", methods: { makeMoney }, components: { FwbButton, FwbInput, FwbSpinner },
    setup(){
        const pageLoading = ref(false);
        const orderIn = ref('')
        const bankOrderIn = ref('')
        const orderOut = ref('')
        const bankOrderOut = ref('')

        const setOrders = (e) => {
            e.preventDefault();
            pageLoading.value = true
            getAxios().post('/api/config/set-orders',{
                orderIn:orderIn.value,
                bankOrderIn:bankOrderIn.value,
                orderOut:orderOut.value,
                bankOrderOut:bankOrderOut.value,
            }).then((res) => {
                pageLoading.value = false
                if(res.data.success === 'success'){
                    alertSuccess('Օրդերները հաջողությամբ պահպանված են')
                    store.commit('setUser',res.data.user)
                    router.push('/')
                }
            })
        }
        return{
            pageLoading,
            orderIn,
            bankOrderIn,
            orderOut,
            bankOrderOut,
            setOrders
        }
    }
}
</script>

<style scoped>

</style>
