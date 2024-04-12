<template>
    <div class="overflow-x-auto sm:rounded-lg container mx-auto pt-10 p-4">
        <div class="flex items-center">
            <h2 class="text-2xl dark:text-white">Հաճախորդների ցուցակ</h2>
            <span v-if="pageLoading" class="pl-2 page-spinner-handler"><fwb-spinner size="6" color="yellow" /></span>
        </div>
        <div class="relative overflow-hidden bg-white shadow-md dark:bg-gray-800 sm:rounded-lg mt-4">
            <div
                class="flex flex-col px-4 py-3 space-y-3 lg:flex-row lg:items-center lg:justify-between lg:space-y-0 lg:space-x-4">
                <div class="flex items-center flex-1 space-x-4">
                    <h5>
                        <span class="text-gray-500">Բոլոր հաճախորդները:</span>
                        <span class="dark:text-white">{{allUsers}}</span>
                    </h5>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left text-gray-800 dark:text-gray-300">
                    <thead
                        class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
                    <tr>
                        <th scope="col" class="px-4 py-3">Անուն</th>
                        <th scope="col" class="px-4 py-3">Ազգանուն</th>
                        <th scope="col" class="px-4 py-3">Անձնագիր</th>
                        <th scope="col" class="px-4 py-3">Ծնվ․</th>
                        <th scope="col" class="px-4 py-3">Հասցե</th>
                        <th scope="col" class="px-4 py-3">Պայմ․ քան․</th>
                        <th scope="col" class="px-4 py-3">Ակտիվ պայմ․</th>
                        <th scope="col" class="px-4 py-3">Էջ</th>


                    </tr>
                    </thead>
                    <tbody>
                    <template v-for="(client,index) in clients">
                        <tr class="border-b text-sm dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer">
                            <td class="px-4 py-3">{{ client.name }}</td>
                            <td class="px-4 py-3">{{ client.surname }}</td>
                            <td class="px-4 py-3">{{ client.passport }}</td>
                            <td class="px-4 py-3">{{ client.dob }}</td>
                            <td class="px-4 py-3">{{ client.address }}</td>
                            <td class="px-4 py-3">{{ client.contracts_count }}</td>
                            <td class="px-4 py-3 font-semibold text-lime-500 dark:text-lime-300">{{ client.active_contracts_count }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <router-link :to="'/profile/' + client.id">
                                    <button
                                        class="transition-all relative bg-blue-600 hover:bg-blue-700 text-white rounded-lg px-3 py-1 whitespace-nowrap">
                                        <i class="fa fa-user"></i>
                                      Էջ
                                    </button>
                                </router-link>

                            </td>
                        </tr>
                    </template>

                    </tbody>
                </table>
            </div>
            <nav
                class="flex flex-col items-start justify-between p-4 space-y-3 md:flex-row md:items-center md:space-y-0"
                aria-label="Table navigation">
              <span class="text-sm font-normal text-gray-500 dark:text-gray-400">
                  Showing
                  <span class="font-semibold text-gray-900 dark:text-white">{{config.from + '-' + config.to}}</span>
                  of
                  <span class="font-semibold text-gray-900 dark:text-white">{{config.total}}</span>
              </span>
                <ul class="inline-flex items-stretch -space-x-px">
                    <li v-for="(link,index) in config.links">
                        <template v-if="index === 0">
                            <button type="button" @click="paginateData(link.url)"
                                    :disabled="!link.url"
                                    class="flex items-center justify-center h-full py-1.5 px-3 ml-0 leading-tight text-gray-500 bg-white
                      border border-gray-300 rounded-l-lg hover:bg-gray-100 hover:text-gray-700 dark:bg-gray-800
                      dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white">
                                <span class="sr-only">Previous</span>
                                <svg class="w-5 h-5" aria-hidden="true" fill="currentColor" viewbox="0 0 20 20"
                                     xmlns="http://www.w3.org/2000/svg">
                                    <path fill-rule="evenodd"
                                          d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z"
                                          clip-rule="evenodd"/>
                                </svg>
                            </button>
                        </template>
                        <template v-else-if="index < config?.links?.length - 1">
                            <button @click="paginateData(link.url)" type="button"
                                    class="flex items-center justify-center px-3 py-2 text-sm leading-tight border"
                                    :disabled="!link.url"
                                    :class="{'text-gray-500 bg-white border-gray-300 hover:bg-gray-100 hover:text-gray-700 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white': !link.active,
                      'text-blue-600 border-gray-300 bg-blue-50 hover:bg-blue-100 hover:text-blue-700 dark:border-gray-700 dark:bg-gray-700 dark:text-white': link.active}"
                            >{{ link.label }}
                            </button>
                        </template>
                        <template v-else>
                            <button @click="paginateData(link.url)" :disabled="!link.url" type="button"
                                    class="flex items-center justify-center h-full px-3 leading-tight text-gray-500
                    bg-white border border-gray-300 rounded-r-lg hover:bg-gray-100 hover:text-gray-700
                     dark:bg-gray-800 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white">
                                <span class="sr-only">Next</span>
                                <svg class="w-5 h-5" aria-hidden="true" fill="currentColor" viewbox="0 0 20 20"
                                     xmlns="http://www.w3.org/2000/svg">
                                    <path fill-rule="evenodd"
                                          d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                                          clip-rule="evenodd"/>
                                </svg>
                            </button>
                        </template>
                    </li>
                </ul>
            </nav>
        </div>
    </div>
</template>

<script>
import axios from "axios";
import {onMounted, onUpdated, ref} from "vue";
import router from "../../router";
import Payment from "../contract/Payment.vue";
import {makeMoney} from "../../calc";
import ContractForm from "../contract/ContractForm.vue";
import UserCard from "../cards/UserCard.vue";
import {initFlowbite} from "flowbite";
import { FwbSpinner } from 'flowbite-vue'
import getAxios from "@/axios";
export default {
    name: "ClientsList",
    components: {UserCard, ContractForm, Payment, FwbSpinner},
    setup() {
        const clients = ref([]);
        const pageLoading = ref(false);
        const config = ref({});
        const allUsers = ref();
        onUpdated(() => {
            initFlowbite();
        })
        const getListData = (url = '/api/get-clients-list') => {
            pageLoading.value = true;
            return getAxios().get(url)
                .then((res) => {
                    pageLoading.value = false;
                  clients.value = res.data.clients.data
                  config.value = res.data.clients
                  allUsers.value = res.data.clients.total
                  return res.data
                })
        }
        const paginateData = (url) => {
            getListData(url)
        }
        getListData()

        return {
          clients,
            paginateData,
            makeMoney,
            pageLoading,
            allUsers,
            config
        }
    }
}
</script>


<style scoped>
.accordion-open {
    width: 30px;
    height: 30px;
}
</style>
