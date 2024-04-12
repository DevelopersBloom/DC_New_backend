<template>
    <div class="overflow-x-auto sm:rounded-lg mx-auto pt-10 p-4">
        <div class="flex items-center">
            <h2 class="text-2xl dark:text-white">Պայմանագրերի ցուցակ </h2>
            <span><fwb-button @click="showFilters = !showFilters" size="sm" color="pink" class="ml-2"><i class="fa fa-filter pr-2"></i>Ֆիլտրեր</fwb-button></span>
            <span class="ml-2" v-if="filtered">
                <fwb-button @click="resetFilters" color="red" size="sm" outline><i class="fa fa-times"></i> Չեղարկել</fwb-button>
            </span>
            <span v-if="pageLoading" class="pl-2 page-spinner-handler"><fwb-spinner class="page-spinner" size="6" color="yellow" /></span>
        </div>
        <div v-show="showFilters" class="relative border overflow-hidden bg-white shadow border-pink-600 shadow-pink-500 dark:bg-gray-800 sm:rounded-lg mt-4 p-4">
            <form @submit.prevent="filterContracts" @keyup.enter="filterContracts">
                <div class="flex flex-col gap-4 justify-between">
                    <div class="grid grid-cols-12 gap-4 items-end filters flex-wrap">
                        <div class="flex flex-col gap-3 col-span-4">
                            <div class="flex gap-4">
                                <div>
                                    <div class="flex justify-between dark:text-gray-300 item-center">
                                        <label class="text-sm " for="id">N</label>
                                        <button type="button" @click="clearFilterValue('ADB_ID')" class="ml-5"><i class="fa fa-times"></i></button>
                                    </div>
                                    <input @keyup.enter="filterContracts" v-model="filters.ADB_ID" type="text" id="ADB_ID" class="min-w-[200px] block w-full p-2 text-gray-900 border border-gray-300 rounded-lg bg-gray-50 sm:text-xs focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                                </div>
                            </div>
                           <div class="flex gap-4">
                                <div>
                                    <div class="flex justify-between dark:text-gray-300 item-center">
                                        <label class="text-sm " for="id">ID</label>
                                        <button type="button" @click="clearFilterValue('id')" class="ml-5"><i class="fa fa-times"></i></button>
                                    </div>
                                    <input @keyup.enter="filterContracts" v-model="filters.id" type="text" id="id" class="min-w-[200px] block w-full p-2 text-gray-900 border border-gray-300 rounded-lg bg-gray-50 sm:text-xs focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                                </div>
                                <div>
                                    <div class="flex justify-between dark:text-gray-300 item-center">
                                        <label class="text-sm " for="number">Հեռ․</label>
                                        <button type="button" @click="clearFilterValue('number')" class="ml-5"><i class="fa fa-times"></i></button>
                                    </div>
                                    <input v-model="filters.number" type="text" id="number" class="min-w-[200px] block w-full p-2 text-gray-900 border border-gray-300 rounded-lg bg-gray-50 sm:text-xs focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                                </div>
                            </div>
                            <div class="flex gap-4">
                                <div>
                                    <div class="flex justify-between dark:text-gray-300 item-center">
                                        <label class="text-sm " for="name">Անուն</label>
                                        <button type="button" @click="clearFilterValue('name')" class="ml-5"><i class="fa fa-times"></i></button>
                                    </div>
                                    <input v-model="filters.name" type="text" id="name" class="min-w-[200px] block w-full p-2 text-gray-900 border border-gray-300 rounded-lg bg-gray-50 sm:text-xs focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                                </div>
                                <div>
                                    <div class="flex justify-between dark:text-gray-300 item-center">
                                        <label class="text-sm dark:text-gray-300" for="surname">Ազգանուն</label>
                                        <button type="button" @click="clearFilterValue('surname')" class="ml-5"><i class="fa fa-times"></i></button>
                                    </div>
                                    <input v-model="filters.surname" type="text" id="surname" class="min-w-[200px] block w-full p-2 text-gray-900 border border-gray-300 rounded-lg bg-gray-50 sm:text-xs focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                                </div>
                            </div>
                            <div class="flex gap-4">
                                <div>
                                    <div class="flex justify-between dark:text-gray-300 item-center">
                                        <label class="text-sm dark:text-gray-300" for="passport">Անձնագիր</label>
                                        <button type="button" @click="clearFilterValue('passport')" class="ml-5"><i class="fa fa-times"></i></button>
                                    </div>
                                    <input v-model="filters.passport" type="text" id="passport" class="min-w-[200px] block w-full p-2 text-gray-900 border border-gray-300 rounded-lg bg-gray-50 sm:text-xs focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                                </div>
                                <div>
                                    <div class="flex justify-between dark:text-gray-300 item-center">
                                        <div class="flex align-center">
                                            <label class="text-sm dark:text-gray-300" for="status">Կարգ․</label>
                                            <span v-if="filters.status" :class="{'bg-orange-400':filters.status === 'initial', 'bg-lime-400': filters.status === 'completed', 'bg-red-600':filters.status === 'executed'}" class="inline-block ml-2 w-8 h-4 rounded-full"></span>
                                        </div>
                                        <button type="button" @click="clearFilterValue('status')" class="ml-5"><i class="fa fa-times"></i></button>
                                    </div>
                                    <select id="status" v-model="filters.status" class="block w-full p-2 text-sm text-gray-900 border border-gray-300 rounded-lg bg-gray-50 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                                        <option :value="''">Ընտրել կարգավիճակը</option>
                                        <option value="completed">Մարված</option>
                                        <option value="initial">Ընթացիկ</option>
                                        <option value="executed">Իրացված</option>
                                    </select>
                                </div>
                            </div>
                            <div class="flex gap-4">
                                <div>
                                    <div class="flex justify-between dark:text-gray-300 item-center">
                                        <label class="text-sm dark:text-gray-300" for="category">Տեսակը</label>
                                        <button type="button" @click="clearFilterValue('category')" class="ml-5"><i class="fa fa-times"></i></button>
                                    </div>
                                    <select id="status" v-model="filters.category" class="min-w-[200px] block w-full p-2 text-sm text-gray-900 border border-gray-300 rounded-lg bg-gray-50 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                                        <option :value="''">Ընտրել Տեսակը</option>
                                        <option v-for="category in filterCategories" :value="category.id">{{category.title}}</option>
                                    </select>
                                </div>
                                <div>
                                    <div class="flex justify-between dark:text-gray-300 item-center">
                                        <label class="text-sm dark:text-gray-300" for="user">Օգտատեր</label>
                                        <button type="button" @click="clearFilterValue('user')" class="ml-5"><i class="fa fa-times"></i></button>
                                    </div>
                                    <select id="status" v-model="filters.user" class="min-w-[200px] block w-full p-2 text-sm text-gray-900 border border-gray-300 rounded-lg bg-gray-50 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                                        <option :value="''">Ընտրել օգտատեր</option>
                                        <option v-for="user in filterUsers" :value="user.id">{{user.name + ' ' + user.surname}}</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="col-span-8 h-full flex gap-4">
                            <div class="flex flex-col justify-between">
                                <div class="border w-max given-filters rounded-lg shadow border-pink-600 shadow-pink-500 p-2">
                                    <h3 class="dark:text-white text-center mb-4">Ուշացած</h3>
                                    <div>
                                        <div class="flex justify-between dark:text-gray-300 item-center">
                                            <label class="text-sm dark:text-gray-300" for="givenFrom">Օրեր</label>
                                            <button type="button" @click="clearFilterValue('passed')" class="ml-5"><i class="fa fa-times"></i></button>
                                        </div>
                                        <input v-model="filters.passed" type="number" id="passed" class="block w-full p-2 text-gray-900 border border-gray-300 rounded-lg bg-gray-50 sm:text-xs focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                                    </div>
                                </div>
                                <div class="border w-max given-filters rounded-lg shadow border-pink-600 shadow-pink-500 p-2">
                                    <h3 class="dark:text-white text-center mb-4">Տրամադրված</h3>
                                    <div class="flex gap-3">
                                        <div>
                                            <div class="flex justify-between dark:text-gray-300 item-center">
                                                <label class="text-sm dark:text-gray-300" for="givenFrom">Սկսած</label>
                                                <button type="button" @click="clearFilterValue('givenFrom')" class="ml-5"><i class="fa fa-times"></i></button>
                                            </div>
                                            <input v-model="filters.givenFrom" type="number" step="100" id="givenFrom" class="block w-full p-2 text-gray-900 border border-gray-300 rounded-lg bg-gray-50 sm:text-xs focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                                        </div>
                                        <div>
                                            <div class="flex justify-between dark:text-gray-300 item-center">
                                                <label class="text-sm dark:text-gray-300" for="givenTo">Մինչև</label>
                                                <button type="button" @click="clearFilterValue('givenTo')" class="ml-5"><i class="fa fa-times"></i></button>
                                            </div>
                                            <input v-model="filters.givenTo" type="number" step="100" id="givenTo" class="block w-full p-2 text-gray-900 border border-gray-300 rounded-lg bg-gray-50 sm:text-xs focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-col justify-end">
                                <div class="border given-filters rounded-lg shadow border-pink-600 shadow-pink-500 p-2">
                                    <h3 class="dark:text-white text-center mb-4">Ամսաթիվ</h3>
                                    <div class="flex gap-3">
                                        <div>
                                            <div class="flex justify-between dark:text-gray-300 item-center">
                                                <label class="text-sm dark:text-gray-300" for="dateFrom">Սկսած</label>
                                                <button type="button" @click="clearFilterValue('dateFrom')" class="ml-5"><i class="fa fa-times"></i></button>
                                            </div>
                                            <datepicker type="date" id="dateFrom" v-model:value="filters.dateFrom"></datepicker>
                                        </div>
                                        <div>
                                            <div class="flex justify-between dark:text-gray-300 item-center">
                                                <label class="text-sm dark:text-gray-300" for="dateTo">Մինչև</label>
                                                <button type="button" @click="clearFilterValue('dateTo')" class="ml-5"><i class="fa fa-times"></i></button>
                                            </div>
                                            <datepicker type="date" id="dateTo" v-model:value="filters.dateTo"></datepicker>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div>
                        <fwb-button color="green" @click="() => filterContracts">Փնտրել</fwb-button>
                    </div>
                </div>
            </form>
        </div>
        <div class="relative overflow-hidden bg-white shadow-md dark:bg-gray-800 sm:rounded-lg mt-4">
            <div
                class="flex flex-col px-4 py-3 space-y-3 lg:flex-row lg:items-center lg:justify-between lg:space-y-0 lg:space-x-4">
                <div class="flex items-center flex-1 space-x-4">
                    <h5>
                        <span class="text-gray-500">Բոլոր պայմանագրերը:</span>
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
                        <th scope="col" class="px-4 py-3">Անձնագիր</th>
                        <th scope="col" class="px-4 py-3">Կարգ․</th>
                        <th scope="col" class="px-4 py-3">Տեսակը</th>
                        <th scope="col" class="px-4 py-3">Արժեքը</th>
                        <th scope="col" class="px-4 py-3">Տրամադրվածը</th>
                        <th scope="col" class="px-4 py-3">Մնացածը</th>
                        <th scope="col" class="px-4 py-3" v-if="user.role === 'admin'">Փոփ․</th>
                        <th scope="col" class="px-4 py-3">Դրամարկղ</th>

                    </tr>
                    </thead>
                    <tbody>
                    <template v-for="(contract,index) in contracts">
                        <tr class="border-b text-xs dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer"
                            :class="{'bg-gray-100 dark:bg-gray-700':contract.id === activeRow}">
                            <td @click="setActiveRow(contract.id)" class="px-4 py-3">
                                <fwb-button color="purple" size="xs">
                                    <svg class="fill-white accordion-open transition-all cursor-pointer"
                                         :class="{'rotate-180': contract.id === activeRow}"
                                         viewBox="0 0 15 15" aria-hidden="true">
                                        <path fill-rule="evenodd" clip-rule="evenodd" d="M3.29279 4.30529C3.48031 4.11782 3.73462 4.0125 3.99979 4.0125C4.26495 4.0125 4.51926 4.11782 4.70679 4.30529L7.99979 7.59829L11.2928 4.30529C11.385 4.20978 11.4954 4.1336 11.6174 4.08119C11.7394 4.02878 11.8706 4.00119 12.0034 4.00004C12.1362 3.99888 12.2678 4.02419 12.3907 4.07447C12.5136 4.12475 12.6253 4.199 12.7192 4.29289C12.8131 4.38679 12.8873 4.49844 12.9376 4.62133C12.9879 4.74423 13.0132 4.87591 13.012 5.00869C13.0109 5.14147 12.9833 5.27269 12.9309 5.39469C12.8785 5.5167 12.8023 5.62704 12.7068 5.71929L8.70679 9.71929C8.51926 9.90676 8.26495 10.0121 7.99979 10.0121C7.73462 10.0121 7.48031 9.90676 7.29279 9.71929L3.29279 5.71929C3.10532 5.53176 3 5.27745 3 5.01229C3 4.74712 3.10532 4.49282 3.29279 4.30529Z"/>
                                    </svg>
                                </fwb-button>

                            </td>
                            <th @click="setActiveRow(contract.id)" class="px-4 py-3">{{ contract.ADB_ID }}</th>
                            <th @click="setActiveRow(contract.id)" class="px-4 py-3">{{ contract.date }}</th>
                            <th>
                                <button :data-popover-target="'popover-user-' + contract.id" type="button" class=" px-4 py-3">{{ contract.name }}</button>
                                <div data-popover :id="'popover-user-' + contract.id" role="tooltip"
                                     class="absolute z-10 invisible inline-block w-64 transition-opacity duration-300
                        opacity-0">
                                    <user-card :user="contract.client"></user-card>
                                </div>
                            </th>
                            <td class="px-4 py-3">{{ contract.surname }}</td>
                            <td class="px-4 py-3">{{ contract.passport }}</td>
                            <td class="px-4 py-3">
                                <div class="inline-block w-10 h-4 mr-2 rounded-full"
                                     :class="{'bg-lime-400': contract.status === 'completed',
                                     'bg-orange-400': contract.status === 'initial',
                                     'bg-red-600': contract.status === 'executed'}"></div>
                            </td>
                            <td>
                                <button :data-popover-target="'popover-category-' + contract.id" type="button" class=" px-4 py-3">{{ contract.items[0]?.category.title}} <span v-if="contract.items.length >1">...</span> </button>
                                <div data-popover :id="'popover-category-' + contract.id" role="tooltip"
                                     class="absolute z-10 invisible inline-block w-64 transition-opacity duration-300
                        opacity-0">
                                    <category-card :contract="contract"></category-card>
                                </div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ makeMoney(contract.worth,true) }} <Dram/></td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ makeMoney(contract.given,true) }} <Dram/></td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ makeMoney(contract.left,true) }} <Dram/></td>
                            <td v-if="user.role === 'admin'" class="px-4 py-3 whitespace-nowrap">
                                <fwb-button v-if="contract.status === 'initial'" size="xs" tag="router-link" :href="'/edit-contract/' + contract.id" color="yellow"><i class="fa fa-edit"></i>Փոփ.</fwb-button>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <router-link :to="'/payments/' + contract.id">
                                    <button
                                        class="transition-all relative bg-blue-600 hover:bg-blue-700 text-white rounded-lg px-3 py-1 whitespace-nowrap">
                                        <i class="fa fa-money-bill"></i>
                                        Վճար․
                                    </button>
                                </router-link>

                            </td>
                        </tr>
                        <tr class="border-b" v-if="contract.id === activeRow">
                            <td :colspan="user.role === 'admin' ? 13 : 12">
                                <div class="flex justify-between gap-2 p-2">
                                    <div class="grow">
                                        <contract-form :contract="contract" mini :editable="false" :flex="false"/>
                                    </div>
                                    <payment mini :model-value="contract"/>
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
import { computed, onMounted, onUpdated, ref, watch } from "vue";
import router from "../../router";
import Payment from "../contract/Payment.vue";
import { alertError, makeMoney } from "../../calc";
import ContractForm from "../contract/ContractForm.vue";
import UserCard from "../cards/UserCard.vue";
import { initFlowbite } from "flowbite";
import { FwbSpinner , FwbButton} from 'flowbite-vue'
import store from "@/store";
import CategoryCard from "@/components/cards/CategoryCard.vue";
import Dram from "@/components/icons/Dram.vue";
export default {
    name: "List",
    components: { Dram, CategoryCard, UserCard, ContractForm, Payment, FwbSpinner, FwbButton},
    setup() {
        const contracts = ref([]);
        const pageLoading = ref(false);
        const activeRow = ref()
        const allContracts = ref();
        const filtered = ref(localStorage.getItem("filtered"));
        const showFilters = ref(localStorage.getItem("showFilters"));
        const config = ref({});
        const user = computed(() => store.state.user || {})
        const filterUsers = ref([]);
        const filterCategories = ref([]);
        onUpdated(() => {
            initFlowbite();
        })
        const clearFilterValue = (val) => {
            filters.value[val] = defaultSearchValues[val]
            localStorage.removeItem(val)
        }
        getAxios().get('/api/get-filters').then((res) => {
            filterCategories.value = res.data.categories
            filterUsers.value = res.data.users
        })
        const defaultSearchValues = {
            id:'',
            name:'',
            surname: '',
            passport:'',
            status: '',
            givenFrom:'',
            givenTo: '',
            dateFrom: null,
            dateTo: null,
            user: '',
            category: '',
            passed: '',
            number: '',
            ADB_ID: '',
        }
        const filters = ref({
            id:localStorage.getItem('id'),
            name:localStorage.getItem('name'),
            surname: localStorage.getItem('surname'),
            passport:localStorage.getItem('passport'),
            status: localStorage.getItem('status'),
            givenFrom:localStorage.getItem('givenFrom'),
            givenTo: localStorage.getItem('givenTo'),
            dateFrom: localStorage.getItem('dateFrom') ? new Date(localStorage.getItem('dateFrom')) : '',
            dateTo: localStorage.getItem('dateTo') ? new Date(localStorage.getItem('dateTo')) : '',
            user: localStorage.getItem('user'),
            category: localStorage.getItem('category'),
            passed: localStorage.getItem('passed'),
            number: localStorage.getItem('number'),
            ADB_ID: localStorage.getItem('ADB_ID'),
        });
        const setActiveRow = (id) => {
            if (activeRow.value === id) {
                activeRow.value = null
            } else {
                activeRow.value = id
            }
        }
        watch(() => showFilters.value,(value) => {
            if(value){
                localStorage.setItem('showFilters','1')
            }else{
                localStorage.removeItem('showFilters')
            }
        })
        watch(() => filtered.value,(value) => {
            if(value){
                localStorage.setItem('filtered','1')
            }else{
                localStorage.removeItem('filtered')
            }
        })
        const resetFilters = () => {
            filters.value = Object.assign({},defaultSearchValues)
            Object.keys(filters.value).map(v => {
                localStorage.removeItem(v)
            })
            showFilters.value = false
            if(filtered.value){
                paginateData()
                filtered.value = false
            }
        }
        const filterContracts = () => {
            Object.entries(filters.value).map(([i,v]) => {
                if(v){
                    localStorage.setItem(i,v)
                }else{
                    localStorage.removeItem(i)
                }
            })
            filtered.value = true;
            paginateData();
        }
        const getListData = (url = '/api/filter-contracts') => {
            pageLoading.value = true;
            return getAxios().post(url,{
                ...filters.value
            })
            .then((res) => {
                pageLoading.value = false;
                contracts.value = res.data.contracts.data
                config.value = res.data.contracts
                allContracts.value = res.data.contracts.total
                return res.data
            }).catch((err) => {
                pageLoading.value = false;
                alertError('Ինչ որ բան այնպես չէ')
            })
        }
        const paginateData = (url) => {
            getListData(url)
        }
        getListData()

        return {
            contracts,
            paginateData,
            activeRow,
            showFilters,
            makeMoney,
            setActiveRow,
            filterContracts,
            filters,
            resetFilters,
            pageLoading,
            clearFilterValue,
            allContracts,
            filterCategories,
            filterUsers,
            filtered,
            config,
            user
        }
    }
}
</script>


<style scoped>
.accordion-open {
    width: 30px;
    height: 30px;
}
.given-filters input::-webkit-outer-spin-button,
.given-filters input::-webkit-inner-spin-button{
    -webkit-appearance: none;
}
</style>
