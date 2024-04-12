<template>
    <div class="overflow-x-auto sm:rounded-lg mx-auto pt-10 p-4">
        <span v-if="pageLoading" class="pl-2 page-spinner-handler"><fwb-spinner size="6" color="yellow" /></span>
        <div class="flex items-center">
            <h2 class="text-2xl dark:text-white">Դրամարկղ</h2>
            <div class="ml-4">
                <div class="flex gap-3">
                    <div class="relative">
                        <label for="dateFrom" class="date_absolute_label dark:text-gray-400 text-gray-600">Սկսած</label>
                        <datepicker type="date" id="dateFrom" v-model:value="filters.dateFrom"></datepicker>
                    </div>
                    <div class="relative">
                        <label for="dateFrom" class="date_absolute_label dark:text-gray-400 text-gray-600">Մինչև</label>
                        <datepicker type="date" id="dateTo" v-model:value="filters.dateTo"></datepicker>
                    </div>
                    <fwb-button color="green" @click="() => getListData()">Փնտրել</fwb-button>
                </div>

            </div>

        </div>
        <div class="relative overflow-hidden bg-white shadow-md dark:bg-gray-800 sm:rounded-lg mt-4">
            <div
                class="flex flex-col px-4 py-3 space-y-3 lg:flex-row lg:items-center lg:justify-between lg:space-y-0 lg:space-x-4">
                <div class="flex items-center flex-1 space-x-4">
                    <h5>
                        <span class="text-gray-500">Բոլոր գործարքները:</span>
                        <span class="dark:text-white">{{allDeals}}</span>
                    </h5>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left text-gray-800 dark:text-gray-300">
                    <thead
                        class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
                    <tr>
                        <th scope="col" class="px-4 py-3">Ամսաթիվ</th>
                        <th scope="col" class="px-4 py-3">Օրդեր</th>
                        <th scope="col" class="px-4 py-3">Պայմ</th>
                        <th scope="col" class="px-4 py-3">Աղբյուր </th>
                        <th scope="col" class="px-4 py-3">Նպատակ </th>
                        <th scope="col" class="px-4 py-3">Ստացող </th>
                        <th scope="col" class="px-4 py-3">Գումար</th>
                        <th scope="col" class="px-4 py-3">Գնահատված</th>
                        <th scope="col" class="px-4 py-3">Տրամադրված</th>
                        <th scope="col" class="px-4 py-3">Դրամարկղ</th>
                        <th scope="col" class="px-4 py-3">Անկանխիկ Դրամարկղ</th>

                    </tr>
                    </thead>
                    <tbody>
                    <template v-for="(deal,index) in deals">
                        <tr
                            class="border-b text-sm dark:border-gray-600 cursor-pointer"
                            :class="{'bg-green-300 dark:bg-green-600':!deal.cash}"
                        >
                            <td class="px-4 py-3">{{ deal.date }}</td>
                            <td class="px-4 py-3"><fwb-button color="yellow" v-if="deal.order" @click="() => downloadOrder(deal.order)" size="xs"><i class="fa fa-download pr-2"></i>{{deal.order.order}}</fwb-button></td>
                            <td class="px-4 py-3"><fwb-button size="xs" tag="router-link" :href="'/payments/' + deal.contract.id" v-if="deal.contract" >{{deal.contract.ADB_ID}}</fwb-button></td>
                            <td class="px-4 py-3">{{ deal.source }}</td>
                            <td class="px-4 py-3">{{ deal.purpose }}</td>
                            <td class="px-4 py-3">{{ deal.receiver }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <template v-if="deal.type === 'in'">
                                    {{deal.amount > 0 ? '+' : ''}}
                                </template>
                                <template v-else>
                                    {{deal.amount > 0 ? '-' : ''}}
                                </template>
                                {{ makeMoney(deal.amount,true) }} <Dram/>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">{{makeMoney(deal.worth,true)}} <Dram/></td>
                            <td class="px-4 py-3 whitespace-nowrap">{{makeMoney(deal.given,true)}} <Dram/></td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ makeMoney((deal.cashbox || 0) + (deal.bank_cashbox || 0),true) }} <Dram/></td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ makeMoney(deal.bank_cashbox,true) }} <Dram/></td>
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
import {ref} from "vue";
import router from "../../router";
import Payment from "../contract/Payment.vue";
import {makeMoney} from "../../calc";
import ContractForm from "../contract/ContractForm.vue";
import UserCard from "../cards/UserCard.vue";
import { FwbButton, FwbSpinner } from 'flowbite-vue'
import getAxios from "@/axios";
import Dram from "@/components/icons/Dram.vue";
export default {
    name: "Cashbox",
    components: { Dram, FwbButton, UserCard, ContractForm, Payment, FwbSpinner},
    setup() {
        const deals = ref([]);
        const pageLoading = ref(false);
        const config = ref({});
        const allDeals = ref();
        const getListData = (url = '/api/get-deals') => {
            pageLoading.value = true;
            return getAxios().post(url,{
                ...filters.value
            })
            .then((res) => {
                pageLoading.value = false;
                deals.value = res.data.deals.data
                config.value = res.data.deals
                allDeals.value = res.data.deals.total
                return res.data
            })
        }
        const defaultSearchValues = {
            dateFrom: null,
            dateTo: null,
        }
        const filters = ref(Object.assign({},defaultSearchValues));
        const paginateData = (url) => {
            getListData(url)
        }
        getListData()
        const downloadOrder = async (order) => {
            const downloadUrl = '/api/download-order/'+ order.id;
            window.open(downloadUrl, '_blank');
        }
        return {
            deals,
            paginateData,
            makeMoney,
            pageLoading,
            allDeals,
            config,
            filters,
            getListData,
            downloadOrder
        }
    }
}
</script>


<style scoped>
.date_absolute_label{
    position: absolute;
    bottom:100%;
    left:50%;
    transform:translateX(-50%);
}
</style>
