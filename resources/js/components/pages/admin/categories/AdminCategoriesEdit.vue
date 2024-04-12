<template>
    <div class="overflow-x-auto sm:rounded-lg container mx-auto pt-10 p-4">
        <div class="flex items-center">
            <h2 class="text-2xl dark:text-white">Ավելացնել ապրանքի տեսակ</h2>
            <span v-if="pageLoading" class="pl-2 page-spinner-handler"><fwb-spinner size="6" color="yellow" /></span>
        </div>
        <form class="mt-4" @submit="submitForm">
            <div class="mb-4">
                <label for="title" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">Անուն</label>
                <input v-model="form.title" type="text" id="title" autocomplete="off"
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
import {onMounted, onUpdated, ref} from "vue";
import router from "@/router";
import { alertError, alertSuccess } from "@/calc";
import {initFlowbite} from "flowbite";
import { FwbButton, FwbSpinner } from 'flowbite-vue'
import getAxios from "@/axios";
import { useRoute } from "vue-router";
export default {
    name: "AdminCategoriesEdit",
    components: { FwbButton, FwbSpinner},
    setup() {
        const pageLoading = ref(false);
        const pawnshops = ref([]);
        const route = useRoute()
        onUpdated(() => {
            initFlowbite();
        })
        const form = ref({})
        const submitForm = (e) => {
            e.preventDefault();
            getAxios().post('/api/admin/update-category',{...form.value}).then((res) => {
                if(res.data.success === 'success'){
                    alertSuccess('Փոփոխությունը կատարված է')
                    router.push('/admin/categories')
                }else {
                    alertError('Ինչ որ բան այնպես չէ')
                }
            })
        }
        const getInfo = () => {
            pageLoading.value = true
            return getAxios().get('/api/admin/edit-category/' + route.params.id)
            .then((res) => {
                pageLoading.value = false;
                form.value = res.data.category
                return res.data
            })
        }
        getInfo()

        return {
            pageLoading,
            pawnshops,
            form,
            submitForm
        }
    }
}
</script>

