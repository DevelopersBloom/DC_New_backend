<template>
    <div class="overflow-x-auto sm:rounded-lg container mx-auto pt-10 p-4">
        <div class="flex items-center">
            <h2 class="text-2xl dark:text-white">Այսօրվա Վճարումներ </h2>
            <span v-if="pageLoading" class="pl-2 page-spinner-handler"><fwb-spinner size="6" color="yellow" /></span>
        </div>

        <div class="relative overflow-hidden bg-white shadow-md dark:bg-gray-800 sm:rounded-lg mt-4">
            <div
                class="flex flex-col px-4 py-3 space-y-3 lg:flex-row lg:items-center lg:justify-between lg:space-y-0 lg:space-x-4">
                <div class="flex items-center flex-1 space-x-4">
                    <h5>
                        <span class="text-gray-500">Բոլոր վճարումները:</span>
                        <span class="dark:text-white">{{allContracts}}</span>
                    </h5>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left text-gray-800 dark:text-gray-300">
                    <thead
                        class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
                    <tr>
                        <th scope="col" class="px-4 py-3"></th>
                        <th scope="col" class="px-4 py-3">N</th>
                        <th scope="col" class="px-4 py-3">Ամսաթիվ</th>
                        <th scope="col" class="px-4 py-3">Անուն</th>
                        <th scope="col" class="px-4 py-3">Ազգանուն</th>
                        <th scope="col" class="px-4 py-3">Արժեքը</th>
                        <th scope="col" class="px-4 py-3">Տրամադրվածը</th>
                        <th scope="col" class="px-4 py-3">Այսօրվա վճ․</th>
                        <th scope="col" class="px-4 py-3">Դրամարկղ</th>

                    </tr>
                    </thead>
                    <tbody>
                    <template v-for="(payment,index) in payments">
                        <tr class="border-b text-xs dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer"
                            :class="{'bg-gray-100 dark:bg-gray-700':payment.id === activeRow}">
                            <td class="px-4 py-3">
                                <svg @click="setActiveRow(payment.id)" class="fill-gray-500 accordion-open transition-all cursor-pointer"
                                     :class="{'rotate-180': payment.id === activeRow}"
                                     viewBox="0 0 20 20" aria-hidden="true">
                                    <path fill-rule="evenodd"
                                          d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                          clip-rule="evenodd"></path>
                                </svg>
                            </td>
                            <th @click="setActiveRow(payment.id)" class="px-4 py-3">{{ payment.contract.id }}</th>
                            <th @click="setActiveRow(payment.id)" class="px-4 py-3">{{ payment.date }}</th>
                            <th>
                                <button :data-popover-target="'popover-user-' + payment.contract.id" type="button" class=" px-4 py-3">{{ payment.contract.name }}</button>
                                <div data-popover :id="'popover-user-' + payment.contract.id" role="tooltip"
                                     class="absolute z-10 invisible inline-block w-64 transition-opacity duration-300
                        opacity-0">
                                    <user-card :user="payment.contract.client"></user-card>
                                </div>
                            </th>
                            <td @click="setActiveRow(payment.id)" class="px-4 py-3">{{ payment.contract.surname }}</td>
                            <td @click="setActiveRow(payment.id)" class="px-4 py-3 whitespace-nowrap">{{ makeMoney(payment.contract.worth, true) }} <Dram/></td>
                            <td @click="setActiveRow(payment.id)" class="px-4 py-3 whitespace-nowrap">{{ makeMoney(payment.contract.given, true) }} <Dram/></td>
                            <td @click="setActiveRow(payment.id)" class="px-4 py-3 whitespace-nowrap">{{ makeMoney(payment.amount, true) }} <Dram/></td>
                            <td @click="setActiveRow(payment.id)" class="px-4 py-3 whitespace-nowrap">
                                <router-link :to="'/payments/' + payment.contract.id">
                                    <button
                                        class="transition-all relative bg-blue-600 hover:bg-blue-700 text-white rounded-lg px-3 py-1 whitespace-nowrap">
                                        <i class="fa fa-money-bill"></i>
                                        Վճար․
                                    </button>
                                </router-link>

                            </td>
                        </tr>
                        <tr class="border-b" v-if="payment.id === activeRow">
                            <td colspan="11">
                                <div class="flex justify-between gap-2 p-2">
                                    <div class="grow">
                                        <contract-form :contract="payment.contract" mini :editable="false" :flex="false"/>
                                    </div>
                                    <payment mini :model-value="payment.contract"/>
                                </div>
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
import getAxios from "../../axios";
import {onMounted, onUpdated, ref} from "vue";
import router from "../../router";
import Payment from "../contract/Payment.vue";
import {makeMoney} from "../../calc";
import ContractForm from "../contract/ContractForm.vue";
import UserCard from "../cards/UserCard.vue";
import { initFlowbite } from "flowbite";
import { FwbSpinner } from 'flowbite-vue'
import Dram from "@/components/icons/Dram.vue";

export default {
    name: "TodaysList",
    components: { Dram, UserCard, ContractForm, Payment, FwbSpinner},
    setup() {
        const payments = ref([]);
        const pageLoading = ref(false);
        const activeRow = ref()
        const allContracts = ref();
        const config = ref({});
        onUpdated(() => {
            initFlowbite();
        })
        const setActiveRow = (id) => {
            if (activeRow.value === id) {
                activeRow.value = null
            } else {
                activeRow.value = id
            }

        }
        const getListData = (url = '/api/get-todays-contracts') => {
            pageLoading.value = true;
            return getAxios().get(url)
            .then((res) => {
                pageLoading.value = false;
                payments.value = res.data.payments.data
                config.value = res.data.payments
                allContracts.value = res.data.payments.total
                return res.data
            })
        }
        const paginateData = (url) => {
            getListData(url)
        }
        getListData()

        return {
            payments,
            paginateData,
            activeRow,
            makeMoney,
            setActiveRow,
            config,
            pageLoading,
            allContracts,
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
