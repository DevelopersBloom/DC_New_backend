<template>
    <div class="overflow-x-auto sm:rounded-lg container mx-auto pt-10 p-4">
        <div class="flex items-center">
            <h2 class="text-2xl dark:text-white">Ավելացնել գնահատող</h2>
            <span v-if="pageLoading" class="pl-2 page-spinner-handler"><fwb-spinner size="6" color="yellow" /></span>
        </div>
        <form class="mt-4" @submit="submitForm">
            <div class="mb-4">
                <label for="pawnshop_id" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">Գրավատուն</label>
                <select id="pawnshop_id"
                        name="pawnshop_id"
                        v-model="form.pawnshop_id"
                        class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5
                                dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                    <option v-for="pawnshop in pawnshops" :value="pawnshop.id">{{pawnshop.city + '/' + pawnshop.address}}</option>
                </select>
            </div>
            <div class="mb-4">
                <label for="name" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">Անուն Ազգանուն</label>
                <input v-model="form.full_name" type="text" id="name" autocomplete="off"
                       class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg
                       focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700
                       dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500
                       dark:focus:border-blue-500" required>
            </div>
            <fwb-button color="light" type="button" class="my-2 mr-4" @click="() => $router.go(-1)">Չեղարկել</fwb-button>
            <fwb-button type="submit">Ավելացնել</fwb-button>
        </form>
    </div>
</template>

<script>
import {onMounted, onUpdated, ref} from "vue";
import router from "@/router";
import { alertError, alertSuccess, makeMoney } from "@/calc";
import {initFlowbite} from "flowbite";
import { FwbButton, FwbSpinner } from 'flowbite-vue'
import getAxios from "@/axios";
export default {
    name: "AdminEvaluatorsAdd",
    components: { FwbButton, FwbSpinner},
    setup() {
        const pageLoading = ref(false);
        const pawnshops = ref([]);
        onUpdated(() => {
            initFlowbite();
        })
        const form = ref({
            full_name: '',
            pawnshop_id: 1
        })
        const submitForm = (e) => {
            e.preventDefault();
            getAxios().post('/api/admin/create-evaluator',{...form.value}).then((res) => {
                if(res.data.success === 'success'){
                    alertSuccess('Գնահատողը ավելացված է')
                    router.push('/admin/evaluators')
                }else {
                    alertError('Ինչ որ բան այնպես չէ')
                }
            })
        }
        const getInfo = () => {
            pageLoading.value = true
            return getAxios().get('/api/admin/get-user-config')
            .then((res) => {
                pageLoading.value = false;
                pawnshops.value = res.data.pawnshops
                return res.data
            })
        }
        getInfo()

        return {
            makeMoney,
            pageLoading,
            pawnshops,
            form,
            submitForm
        }
    }
}
</script>

