<template>
    <div class="overflow-x-auto sm:rounded-lg container mx-auto pt-10 p-4">
        <div class="flex items-center">
            <h2 class="text-2xl dark:text-white">Ապրանքի տեսակների ցուցակ</h2>
            <span v-if="pageLoading" class="pl-2 page-spinner-handler"><fwb-spinner size="6" color="yellow" /></span>
        </div>
        <div class="relative overflow-hidden bg-white shadow-md dark:bg-gray-800 sm:rounded-lg mt-4">
            <div
                class="flex flex-col px-4 py-3 space-y-3 lg:flex-row lg:items-center lg:justify-between lg:space-y-0 lg:space-x-4">
                <div class="flex items-center justify-between flex-1 space-x-4">
                    <h5>
                        <span class="text-gray-500">Բոլոր տեսակները:</span>
                        <span class="dark:text-white">{{allUsers}}</span>
                    </h5>
                    <div>
                        <fwb-button size="sm" pill tag="router-link" href="/admin/categories/create"><i class="fa fa-list pr-1"></i>Ավելացնել</fwb-button>
                    </div>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left text-gray-800 dark:text-gray-300">
                    <thead
                        class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
                    <tr>
                        <th scope="col" class="px-4 py-3">Անուն</th>
                        <th scope="col" class="px-4 py-3">Պայմ․ քան․</th>
                        <th scope="col" class="px-4 py-3">Փոփ․</th>

                    </tr>
                    </thead>
                    <tbody>
                    <template v-for="(category,index) in categories">
                        <tr class="border-b text-sm dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer">
                            <td class="px-4 py-3">{{ category.title }}</td>
                            <td class="px-4 py-3">{{ category.contracts_count }}</td>
                            <td class="px-4 py-3">
                                <fwb-button size="xs" tag="router-link" :href="'/admin/categories/edit/' + category.id" color="yellow"><i class="fa fa-edit"></i>Փոփ.</fwb-button>
                            </td>
                        </tr>
                    </template>

                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>

<script>
import {onMounted, onUpdated, ref} from "vue";
import router from "@/router";
import {initFlowbite} from "flowbite";
import { FwbSpinner , FwbButton} from 'flowbite-vue'
import getAxios from "@/axios";
export default {
    name: "AdminCategoriesList",
    components: { FwbSpinner, FwbButton},
    setup() {
        const categories = ref([]);
        const pageLoading = ref(false);
        const allUsers = ref();
        onUpdated(() => {
            initFlowbite();
        })
        const getListData = (url = '/api/admin/get-categories') => {
            pageLoading.value = true;
            return getAxios().get(url)
            .then((res) => {
                pageLoading.value = false;
                categories.value = res.data.categories
                return res.data
            })
        }
        getListData().then((data) => {
            allUsers.value = categories.value.length
        })

        return {
            categories,
            pageLoading,
            allUsers,
        }
    }
}
</script>
