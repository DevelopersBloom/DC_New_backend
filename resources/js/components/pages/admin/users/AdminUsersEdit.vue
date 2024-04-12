<template>
    <div class="overflow-x-auto sm:rounded-lg container mx-auto pt-10 p-4">
        <span v-if="pageLoading" class="pl-2 page-spinner-handler"><fwb-spinner size="6" color="yellow" /></span>
        <div class="flex items-center justify-between">
            <h2 class="text-2xl dark:text-white">Փոփոխել օգտատիրոջ տվյալները</h2>
            <fwb-button color="red" v-if="form.id && form.id !== user.id" @click="deleteUser">Ջնջել Օգտատիրոջը</fwb-button>
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
                <label for="name" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">Անուն</label>
                <input v-model="form.name" type="text" id="name" autocomplete="off"
                       class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg
                       focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700
                       dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500
                       dark:focus:border-blue-500" required>
            </div>
            <div class="mb-4">
                <label for="surname" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">Ազգանուն</label>
                <input v-model="form.surname" type="text" id="surname" autocomplete="off"
                       class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg
                       focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700
                       dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500
                       dark:focus:border-blue-500" required>
            </div>
            <div class="mb-4">
                <label for="email" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">Մեյլ</label>
                <input v-model="form.email" type="email" id="email" autocomplete="off"
                       class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg
                       focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700
                       dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500
                       dark:focus:border-blue-500" required>
            </div>
            <div class="mb-4">
                <label for="role" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">Դեր</label>
                <fwb-select :options="roles" v-model="form.role"></fwb-select>
            </div>
            <div class="mb-4">
                <fwb-checkbox v-model="changePassword" label="Փոձոխել գաղտնաբառը" />
            </div>
            <div class="mb-4" v-if="changePassword">
                <label for="email" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">Նոր գաղտնաբառ</label>
                <input v-model="newPassword" :required="changePassword" type="text" autocomplete="off"
                       class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg
                       focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700
                       dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500
                       dark:focus:border-blue-500" required>
            </div>
            <fwb-button color="light" type="button" class="my-2 mr-4" @click="() => $router.go(-1)">Չեղարկել</fwb-button>
            <fwb-button type="submit">Պահպանել</fwb-button>
        </form>
    </div>
</template>

<script>
import { computed, onMounted, onUpdated, ref } from "vue";
import router from "@/router";
import { alertError, alertSuccess, makeMoney } from "@/calc";
import {initFlowbite} from "flowbite";
import { FwbButton, FwbCheckbox, FwbSelect, FwbSpinner } from 'flowbite-vue'
import getAxios from "@/axios";
import { useRoute } from "vue-router";
import store from "@/store";
export default {
    name: "AdminUsersEdit",
    components: { FwbSelect, FwbCheckbox, FwbButton, FwbSpinner},
    setup() {
        const pageLoading = ref(false);
        const pawnshops = ref([]);
        const route  = useRoute();
        onUpdated(() => {
            initFlowbite();
        })
        const roles = [
            {
                value: 'admin', name: 'admin'
            },
            {
                value: 'user', name: 'user'
            },
        ]
        const changePassword = ref(false)
        const newPassword = ref('')
        const user = computed(() => store.state.user)
        const form = ref({})
        const submitForm = (e) => {
            e.preventDefault();
            getAxios().post('/api/admin/update-user',{
                ...form.value,
                changePassword:changePassword.value,
                newPassword:newPassword.value
            }).then((res) => {
                if(res.data.success === 'success'){
                    if(form.value.id === user.value.id){
                        store.commit('setUser',res.data.user)
                    }
                    alertSuccess('Փոփոխությունը կատարված է')
                    router.push('/admin/users')
                }else {
                    alertError('Ինչ որ բան այնպես չէ')
                }
            })
        }
        const deleteUser = () => {
            if(confirm('Դուք համոզվա՞ծ եք որ ցանկանում եք ջնջել օգտատիրոջը')){
                getAxios().post('/api/admin/delete-user',{...form.value}).then((res) => {
                    if(res.data.success === 'success'){
                        alertSuccess('Օգտատերը ջնջված է')
                        router.push('/admin/users')
                    }else {
                        alertError('Ինչ որ բան այնպես չէ')
                    }
                })
            }

        }
        const getInfo = () => {
            pageLoading.value = true
            return getAxios().get('/api/admin/edit-user/' + route.params.id)
            .then((res) => {
                pageLoading.value = false;
                form.value = res.data.user
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
            roles,
            submitForm,
            user,
            deleteUser,
            changePassword,
            newPassword
        }
    }
}
</script>

