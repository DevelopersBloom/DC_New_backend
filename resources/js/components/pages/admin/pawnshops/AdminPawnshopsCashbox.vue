<template>
    <div class="overflow-x-auto sm:rounded-lg container mx-auto pt-10 p-4">
        <div class="flex items-center">
            <h2 class="text-2xl dark:text-white">Դրամարկղ</h2>
            <span v-if="pageLoading" class="pl-2 page-spinner-handler"><fwb-spinner size="6" color="yellow" /></span>
        </div>
        <fwb-modal persistent v-if="!authenticated">
            <template #header>
                <div class="flex items-center text-lg dark:text-gray-400">
                    Նույնականացում
                </div>
            </template>
            <template #body>
                <p class="text-gray-500 dark:text-gray-400 mb-2">
                    Մուտքագրեք ձեր գաղտնաբառը
                </p>
                <div>
                    <fwb-input :validation-status="hasError? 'error' : 'success'" v-model="password" autocomplete="off" type="text" name="check_pass" id="check_pass">
                        <template v-if="hasError" #validationMessage>
                            Սխալ գաղտնաբառ
                        </template>
                    </fwb-input>
                </div>
            </template>
            <template #footer>
                <div class="flex justify-end">
                    <fwb-button color="green" @click="checkAuthority">
                        Հարցում
                    </fwb-button>
                </div>
            </template>
        </fwb-modal>
        <section v-if="authenticated" class="bg-white dark:bg-gray-800 rounded-lg mt-8 p-4 shadow border mb-4 border-orange-500">
            <form class="" @submit="submitForm">
                <div class="mb-4">
                    <label for="city" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">Կանխիկ Դրամարկղ</label>
                    <div class="flex gap-3 items-center">
                        <input v-model="form.cashbox" type="number" id="cashbox" autocomplete="off"
                               class="w-max bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg
                       focus:ring-blue-500 focus:border-blue-500 block  p-2.5 dark:bg-gray-700
                       dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500
                       dark:focus:border-blue-500" required>
                        <span class="dark:text-white">{{makeMoney(form.cashbox,true)}}</span>
                    </div>
                </div>
                <div class="mb-4">
                    <label for="city" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">Անկանխիկ Դրամարկղ</label>
                    <div class="flex gap-3 items-center">
                        <input v-model="form.bank_cashbox" type="number" id="bank_cashbox" autocomplete="off"
                               class="w-max bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg
                       focus:ring-blue-500 focus:border-blue-500 block  p-2.5 dark:bg-gray-700
                       dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500
                       dark:focus:border-blue-500" required>
                        <span class="dark:text-white">{{makeMoney(form.bank_cashbox,true)}}</span>
                    </div>

                </div>
                <div class="mb-4">
                    <label for="city" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">Ընդհանուր գնահատված</label>
                    <div class="flex gap-3 items-center">
                        <input v-model="form.worth" type="number" id="worth" autocomplete="off"
                               class="w-max bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg
                       focus:ring-blue-500 focus:border-blue-500 block  p-2.5 dark:bg-gray-700
                       dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500
                       dark:focus:border-blue-500" required>
                        <span class="dark:text-white">{{makeMoney(form.worth,true)}}</span>
                    </div>

                </div>
                <div class="mb-4">
                    <label for="city" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">Ընդհանուր տրամադրված</label>
                    <div class="flex gap-3 items-center">
                        <input v-model="form.given" type="number" id="given" autocomplete="off"
                               class="w-max bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg
                       focus:ring-blue-500 focus:border-blue-500 block  p-2.5 dark:bg-gray-700
                       dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500
                       dark:focus:border-blue-500" required>
                        <span class="dark:text-white">{{makeMoney(form.given,true)}}</span>
                    </div>
                </div>
                <div class="mb-4">
                    <label for="city" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">Ապպա</label>
                    <div class="flex gap-3 items-center">
                        <input v-model="form.insurance" name="insurance" type="number" id="given" autocomplete="off"
                               class="w-max bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg
                       focus:ring-blue-500 focus:border-blue-500 block  p-2.5 dark:bg-gray-700
                       dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500
                       dark:focus:border-blue-500" required>
                        <span class="dark:text-white">{{makeMoney(form.insurance,true)}}</span>
                    </div>
                </div>
                <div class="mb-4">
                    <label for="city" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">Ներգրավված դրամական միջոցներ</label>
                    <div class="flex gap-3 items-center">
                        <input v-model="form.funds" name="insurance" type="number" id="given" autocomplete="off"
                               class="w-max bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg
                       focus:ring-blue-500 focus:border-blue-500 block  p-2.5 dark:bg-gray-700
                       dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500
                       dark:focus:border-blue-500" required>
                        <span class="dark:text-white">{{makeMoney(form.funds,true)}}</span>
                    </div>
                </div>
                <div class="pt-2">
                    <fwb-button color="light" type="button" class="mr-4" @click="() => $router.go(-1)">Չեղարկել</fwb-button>
                    <fwb-button type="submit">Պահպանել</fwb-button>
                </div>
            </form>
        </section>

    </div>
</template>

<script>
import {onMounted, onUpdated, ref} from "vue";
import router from "@/router";
import { alertError, alertSuccess } from "@/calc";
import {initFlowbite} from "flowbite";
import { FwbButton, FwbInput, FwbModal, FwbSpinner } from 'flowbite-vue'
import getAxios from "@/axios";
import { useRoute } from "vue-router";
import { makeMoney } from "@/calc";
export default {
    name: "AdminPawnshopsCashbox",
    components: { FwbInput, FwbModal, FwbButton, FwbSpinner},
    methods:{makeMoney},
    setup() {
        const route = useRoute();
        const pageLoading = ref(false);
        const authenticated = ref(false);
        const password = ref('');
        const hasError = ref(false);
        const form = ref({})
        const submitForm = (e) => {
            e.preventDefault();
            getAxios().post('/api/admin/update-cashbox',{...form.value}).then((res) => {
                if(res.data.success === 'success'){
                    alertSuccess('Փոփոխությունը կատարված է')
                    router.push('/admin/pawnshops')
                }else {
                    alertError('Ինչ որ բան այնպես չէ')
                }
            })
        }
        const checkAuthority = () => {
            getAxios().post('/api/admin/check-authority',{password:password.value}).then((res) => {
                if(res.data.success === 'success'){
                    hasError.value = false
                    authenticated.value = true
                }else{
                    hasError.value = true
                }
            })
        }
        const getInfo = () => {
            pageLoading.value = true
            return getAxios().get('/api/admin/edit-pawnshop/' + route.params.id)
            .then((res) => {
                pageLoading.value = false;
                form.value = res.data.pawnshop
                return res.data
            })
        }
        getInfo()

        return {
            pageLoading,
            form,
            hasError,
            authenticated,
            submitForm,
            checkAuthority,
            password
        }
    }
}
</script>

