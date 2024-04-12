<template>
    <div class="overflow-x-auto sm:rounded-lg container mx-auto pt-10 p-4">
        <div class="flex items-center">
            <h2 class="text-2xl dark:text-white">Ավելացնել գրավատուն</h2>
            <span v-if="pageLoading" class="pl-2 page-spinner-handler"><fwb-spinner size="6" color="yellow" /></span>
        </div>
        <form class="mt-4" @submit="submitForm">
            <div class="mb-4">
                <label for="city" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">ՔԱՂԱՔ</label>
                <input v-model="form.city" type="text" id="city" autocomplete="off"
                       class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg
                       focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700
                       dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500
                       dark:focus:border-blue-500" required>
            </div>
            <div class="mb-4">
                <label for="address" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">ՀԱՍՑԵ</label>
                <input v-model="form.address" type="text" id="address" autocomplete="off"
                       class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg
                       focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700
                       dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500
                       dark:focus:border-blue-500" required>
            </div>
            <div class="mb-4">
                <label for="license" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white uppercase">լիցենզիա</label>
                <input v-model="form.license" type="text" id="license" autocomplete="off"
                       class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg
                       focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700
                       dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500
                       dark:focus:border-blue-500" required>
            </div>
            <div class="mb-4">
                <label for="representative" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white uppercase">Գրավատան ներկայացուցիչ</label>
                <input v-model="form.representative" type="text" id="representative" autocomplete="off"
                       class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg
                       focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700
                       dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500
                       dark:focus:border-blue-500" required>
            </div>
            <div class="mb-4">
                <label for="telephone" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white uppercase">Քաղ․ հեռ․</label>
                <input v-model="form.telephone" type="text" id="telephone" autocomplete="off"
                       class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg
                       focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700
                       dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500
                       dark:focus:border-blue-500" required>
            </div>
            <div class="mb-4">
                <label for="phone1" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white uppercase">Բջջ․ հեռ․ 1</label>
                <input v-model="form.phone1" type="text" id="phone1" autocomplete="off"
                       class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg
                       focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700
                       dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500
                       dark:focus:border-blue-500" required>
            </div>
            <div class="mb-4">
                <label for="phone2" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white uppercase">Բջջ․ հեռ․ 2</label>
                <input v-model="form.phone2" type="text" id="phone2" autocomplete="off"
                       class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg
                       focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700
                       dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500
                       dark:focus:border-blue-500">
            </div>
            <div class="mb-4">
                <label for="email" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white uppercase">էլ․ Հասցե</label>
                <input v-model="form.email" type="text" id="email" autocomplete="off"
                       class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg
                       focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700
                       dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500
                       dark:focus:border-blue-500" required>
            </div>
            <div class="mb-4">
                <label for="bank" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white uppercase">Բանկ</label>
                <input v-model="form.bank" type="text" id="bank" autocomplete="off"
                       class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg
                       focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700
                       dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500
                       dark:focus:border-blue-500" required>
            </div>
            <div class="mb-4">
                <label for="card_account_number" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white uppercase">Քարտային հաշվեհամար </label>
                <input v-model="form.card_account_number" type="text" id="card_account_number" autocomplete="off"
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
import { alertError, alertSuccess } from "@/calc";
import {initFlowbite} from "flowbite";
import { FwbButton, FwbSpinner } from 'flowbite-vue'
import getAxios from "@/axios";
export default {
    name: "AdminPawnshopsAdd",
    components: { FwbButton, FwbSpinner},
    setup() {
        const pageLoading = ref(false);
        const pawnshops = ref([]);
        onUpdated(() => {
            initFlowbite();
        })
        const form = ref({
            city: '',
            address:'',
            license:'',
            representative:'',
            telephone:'',
            phone1:'',
            phone2:'',
            email:'',
            bank:'',
            card_account_number:'',
        })
        const submitForm = (e) => {
            e.preventDefault();
            getAxios().post('/api/admin/create-pawnshop',{...form.value}).then((res) => {
                if(res.data.success === 'success'){
                    alertSuccess('Գրավատունը ավելացված է')
                    router.push('/admin/pawnshops')
                }else {
                    alertError('Ինչ որ բան այնպես չէ')
                }
            })
        }

        return {
            pageLoading,
            pawnshops,
            form,
            submitForm
        }
    }
}
</script>

