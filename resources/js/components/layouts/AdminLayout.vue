<template>
    <nav class="bg-white z-30 border-gray-200 dark:bg-gray-800">
        <div class="max-w-screen-2xl flex flex-wrap items-center justify-between mx-auto p-4">
            <div class="flex items-center">
                <router-link to="/" class="flex items-center">
                    <svg class="w-10 h-10 pr-2 fill-black dark:fill-white"  viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg">
                        <g>
                            <g>
                                <rect width="48" height="48" fill="none"/>
                            </g>
                            <g id="Q3_icons" data-name="Q3 icons">
                                <g>
                                    <polygon points="30.7 16.1 25.3 6 22.7 6 17.3 16.1 30.7 16.1"/>
                                    <polygon points="18.1 6 11 6 3 16.1 12.8 16.1 18.1 6"/>
                                    <polygon points="45 16.1 37 6 29.9 6 35.2 16.1 45 16.1"/>
                                    <polygon points="16.7 20.2 24 44 31.3 20.2 16.7 20.2"/>
                                    <polygon points="30.3 37.3 45 20.2 35.5 20.2 30.3 37.3"/>
                                    <polygon points="3.1 20.2 17.7 37.3 12.5 20.2 3.1 20.2"/>
                                </g>
                            </g>
                        </g>
                    </svg>
                    <span class="self-center text-2xl font-semibold whitespace-nowrap dark:text-white">Diamond Credit</span>
                </router-link>
                <div class=" pl-10 grow hidden">
                    <div class="relative">
                        <label for="default-search" class="mb-2 text-sm font-medium text-gray-900 sr-only dark:text-white">Search</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                                <svg class="w-4 h-4 text-gray-500 dark:text-gray-400" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 20 20">
                                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m19 19-4-4m0-7A7 7 0 1 1 1 8a7 7 0 0 1 14 0Z"/>
                                </svg>
                            </div>
                            <input type="search" id="default-search"
                                   @focus="focusSearchInput" @blur="blurSearchInput" v-model="searchValue"
                                   @input="search"
                                   autocomplete="off"
                                   class="block w-auto p-2 pl-10 text-sm text-gray-900 border border-gray-300 rounded-lg
                       bg-gray-50 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700
                       dark:border-gray-600 dark:placeholder-gray-400 dark:text-white
                       dark:focus:ring-blue-500 dark:focus:border-blue-500" placeholder="Փնտրել հաճախորդ, պայմ․" required>
                        </div>
                        <div class="absolute mt-1 top-full z-30 left-0 right-0 bg-gray-100 dark:bg-gray-900 rounded-lg shadow border border-gray-200 dark:border-gray-700" v-if="searchActive && searchValue">
                            <dl class="text-gray-900 divide-y divide-gray-200 dark:text-white dark:divide-gray-700 p-4">
                                <h3 class="text-gray-500 text-sm dark:text-gray-400 mb-2">Հաճախորդ</h3>
                                <template v-if="searchClients.length">
                                    <div v-for="value in searchClients">
                                        <router-link :to="'/profile/' + value.id">
                                            <div class="flex flex-col pb-1 cursor-pointer hover:bg-gray-200 dark:hover:bg-gray-700 rounded-lg p-1">
                                                <dt class="text-gray-500 text-xs dark:text-gray-400">{{value.name + '  ' + value.surname}}</dt>
                                                <dd class="text-sm font-medium">{{value.email}}</dd>
                                            </div>
                                        </router-link>

                                    </div>
                                </template>
                                <template v-else>
                                    <p class="text-gray-500 text-xs dark:text-gray-400">Արդյունքներ չեն գտնվել</p>
                                </template>

                            </dl>
                            <dl class="text-gray-900 divide-y divide-gray-200 dark:text-white dark:divide-gray-700 p-4">
                                <h3 class="text-gray-500 text-sm dark:text-gray-400 mb-2">Պայմանագիր</h3>
                                <template v-if="searchContracts.length">
                                    <div v-for="value in searchContracts">
                                        <router-link :to="'/payments/' + value.id">
                                            <div class="flex flex-col pb-1 cursor-pointer hover:bg-gray-200 dark:hover:bg-gray-700 rounded-lg p-1">
                                                <dt class="text-gray-500 text-xs dark:text-gray-400">{{value.name + '  ' + value.surname}}</dt>
                                                <dt class="text-gray-500 text-xs dark:text-gray-400">{{value.date}}</dt>
                                                <dd class="text-sm font-medium">{{makeMoney(value.worth,true)}}/{{makeMoney(value.given,true)}}</dd>
                                            </div>
                                        </router-link>

                                    </div>
                                </template>
                                <template v-else>
                                    <p class="text-gray-500 text-xs dark:text-gray-400">Արդյունքներ չեն գտնվել</p>
                                </template>

                            </dl>
                        </div>
                    </div>

                </div>
            </div>

            <button data-collapse-toggle="navbar-default" type="button"
                    class="inline-flex items-center p-2 w-10 h-10 justify-center text-sm text-gray-500
              rounded-lg md:hidden hover:bg-gray-100 focus:outline-none focus:ring-2
              focus:ring-gray-200 dark:text-gray-400 dark:hover:bg-gray-700 dark:focus:ring-gray-600"
                    aria-controls="navbar-default" aria-expanded="false">
                <span class="sr-only">Open main menu</span>
                <svg class="w-5 h-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 17 14">
                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M1 1h15M1 7h15M1 13h15"/>
                </svg>
            </button>
            <div class="hidden w-full md:block md:w-auto" id="navbar-default">
                <ul class="font-medium flex flex-col p-4 md:p-0 mt-4 items-center rounded-lg md:flex-row md:space-x-8 md:mt-0 md:border-0">
                    <li>
                        <div class="relative">
                            <div class="notif-pinner" v-if="hasNewComment"></div>
                            <button id="notif-dropdown-button" data-dropdown-toggle="notifications" class="" type="button">
                                <i class="fa fa-comment dark:text-white"></i>
                            </button>
                            <div id="notifications" class="z-10 hidden bg-white rounded-lg shadow w-48 dark:bg-gray-700 p-2">
                                <fwb-button @click="goToDiscussions" size="xs" class=" w-full mb-2">
                                    Քննարկումներ <i class="fa fa-link"></i>
                                </fwb-button>


                                <textarea id="message" v-model="discussionText" rows="4" class="block p-2.5 w-full text-sm text-gray-900 bg-gray-50 rounded-lg border border-gray-300 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"></textarea>
                                <div class="p-1 flex justify-end">
                                    <fwb-button :disabled="!discussionText" size="xs" @click="sendComment">Ուղղարկել</fwb-button>
                                </div>
                            </div>
                        </div>

                    </li>
                    <li>
                        <button @click="switchColorMode" class="text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:focus:ring-gray-700 rounded-lg text-sm p-2.5 w-10 h-10 inline-flex items-center justify-center">
                            <i class="fa fa-moon" v-if="mode==='light'"></i>
                            <i class="fa fa-sun text-white" v-else></i>
                        </button>
                    </li>
                    <li>
                        <auth-card @logout="logout"></auth-card>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    <button data-drawer-target="default-sidebar" data-drawer-toggle="default-sidebar" aria-controls="default-sidebar" type="button" class="inline-flex items-center p-2 mt-2 ml-3 text-sm text-gray-500 rounded-lg sm:hidden hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-gray-200 dark:text-gray-400 dark:hover:bg-gray-700 dark:focus:ring-gray-600">
        <span class="sr-only">Open sidebar</span>
        <svg class="w-6 h-6" aria-hidden="true" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
            <path clip-rule="evenodd" fill-rule="evenodd" d="M2 4.75A.75.75 0 012.75 4h14.5a.75.75 0 010 1.5H2.75A.75.75 0 012 4.75zm0 10.5a.75.75 0 01.75-.75h7.5a.75.75 0 010 1.5h-7.5a.75.75 0 01-.75-.75zM2 10a.75.75 0 01.75-.75h14.5a.75.75 0 010 1.5H2.75A.75.75 0 012 10z"></path>
        </svg>
    </button>
    <aside id="default-sidebar" class="fixed top-[74px] left-0 z-20 w-64 h-screen transition-transform -translate-x-full sm:translate-x-0" aria-label="Sidebar">
        <div class="h-full px-3 py-4 overflow-y-auto bg-gray-50 dark:bg-gray-800">
            <ul class="space-y-2 font-medium">
<!--                <li>-->
<!--                    <router-link to="/admin/statistics" class="flex items-center p-2 text-gray-900 rounded-lg dark:text-white hover:bg-gray-100 dark:hover:bg-gray-700 group">-->
<!--                        <i class="fa-solid fa-chart-pie"></i>-->
<!--                        <span class="flex-1 ml-3 whitespace-nowrap">Ստատիստիկա</span>-->
<!--                    </router-link>-->
<!--                </li>-->
                <li>
                    <router-link to="/admin/reports" class="flex items-center p-2 text-gray-900 rounded-lg dark:text-white hover:bg-gray-100 dark:hover:bg-gray-700 group">
                        <i class="fa-regular fa-file"></i>
                        <span class="flex-1 ml-3 whitespace-nowrap">Հաշվետվություն</span>
                    </router-link>
                </li>
                <li>
                    <button type="button" class="flex items-center w-full p-2 text-base text-gray-900 transition duration-75 rounded-lg group hover:bg-gray-100 dark:text-white dark:hover:bg-gray-700"
                            aria-controls="pawnshops"
                            data-collapse-toggle="pawnshops">
                        <i class="fa fa-building"></i>
                        <span class="flex-1 ml-3 text-left whitespace-nowrap">Գրավատներ</span>
                        <svg class="w-3 h-3" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 10 6">
                            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 4 4 4-4"/>
                        </svg>
                    </button>
                    <ul id="pawnshops" class="hidden py-2 space-y-2">
                        <li>
                            <router-link to="/admin/pawnshops" class="flex items-center w-full p-2 text-gray-900 transition duration-75 rounded-lg pl-11 group hover:bg-gray-100 dark:text-white dark:hover:bg-gray-700">Ցուցակ</router-link>
                        </li>
                        <li>
                            <router-link to="/admin/pawnshops/create" class="flex items-center w-full p-2 text-gray-900 transition duration-75 rounded-lg pl-11 group hover:bg-gray-100 dark:text-white dark:hover:bg-gray-700">Ավելացնել</router-link>
                        </li>
                    </ul>
                </li>
                <li>
                    <button type="button" class="flex items-center w-full p-2 text-base text-gray-900 transition duration-75 rounded-lg group hover:bg-gray-100 dark:text-white dark:hover:bg-gray-700"
                            aria-controls="users"
                            data-collapse-toggle="users">
                        <i class="fa fa-user"></i>
                        <span class="flex-1 ml-3 text-left whitespace-nowrap">Օգտատերեր</span>
                        <svg class="w-3 h-3" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 10 6">
                            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 4 4 4-4"/>
                        </svg>
                    </button>
                    <ul id="users" class="hidden py-2 space-y-2">
                        <li>
                            <router-link to="/admin/users" class="flex items-center w-full p-2 text-gray-900 transition duration-75 rounded-lg pl-11 group hover:bg-gray-100 dark:text-white dark:hover:bg-gray-700">Ցուցակ</router-link>
                        </li>
                        <li>
                            <router-link to="/admin/users/create" class="flex items-center w-full p-2 text-gray-900 transition duration-75 rounded-lg pl-11 group hover:bg-gray-100 dark:text-white dark:hover:bg-gray-700">Ավելացնել</router-link>
                        </li>
                    </ul>
                </li>
                <li>
                    <button type="button" class="flex items-center w-full p-2 text-base text-gray-900 transition duration-75 rounded-lg group hover:bg-gray-100 dark:text-white dark:hover:bg-gray-700"
                            aria-controls="categories"
                            data-collapse-toggle="categories">
                        <i class="fa fa-list"></i>
                        <span class="flex-1 ml-3 text-left whitespace-nowrap">Ապր․ տեսակներ</span>
                        <svg class="w-3 h-3" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 10 6">
                            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 4 4 4-4"/>
                        </svg>
                    </button>
                    <ul id="categories" class="hidden py-2 space-y-2">
                        <li>
                            <router-link to="/admin/categories" class="flex items-center w-full p-2 text-gray-900 transition duration-75 rounded-lg pl-11 group hover:bg-gray-100 dark:text-white dark:hover:bg-gray-700">Ցուցակ</router-link>
                        </li>
                        <li>
                            <router-link to="/admin/categories/create" class="flex items-center w-full p-2 text-gray-900 transition duration-75 rounded-lg pl-11 group hover:bg-gray-100 dark:text-white dark:hover:bg-gray-700">Ավելացնել</router-link>
                        </li>
                    </ul>
                </li>
                <li>
                    <button type="button" class="flex items-center w-full p-2 text-base text-gray-900 transition duration-75 rounded-lg group hover:bg-gray-100 dark:text-white dark:hover:bg-gray-700"
                            aria-controls="evaluators"
                            data-collapse-toggle="evaluators">
                        <i class="fa-solid fa-money-bill"></i>
                        <span class="flex-1 ml-3 text-left whitespace-nowrap">Գնահատողներ</span>
                        <svg class="w-3 h-3" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 10 6">
                            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 4 4 4-4"/>
                        </svg>
                    </button>
                    <ul id="evaluators" class="hidden py-2 space-y-2">
                        <li>
                            <router-link to="/admin/evaluators" class="flex items-center w-full p-2 text-gray-900 transition duration-75 rounded-lg pl-11 group hover:bg-gray-100 dark:text-white dark:hover:bg-gray-700">Ցուցակ</router-link>
                        </li>
                        <li>
                            <router-link to="/admin/evaluators/create" class="flex items-center w-full p-2 text-gray-900 transition duration-75 rounded-lg pl-11 group hover:bg-gray-100 dark:text-white dark:hover:bg-gray-700">Ավելացնել</router-link>
                        </li>
                    </ul>
                </li>
                <li>
                    <router-link to="/admin/discounts" class="flex items-center p-2 text-gray-900 rounded-lg dark:text-white hover:bg-gray-100 dark:hover:bg-gray-700 group">
                        <i class="fa-solid fa-chart-pie"></i>
                        <span class="flex-1 ml-3 whitespace-nowrap">Զեղչեր</span>
                    </router-link>
                </li>
<!--                <li>-->
<!--                    <a href="#" class="flex items-center p-2 text-gray-900 rounded-lg dark:text-white hover:bg-gray-100 dark:hover:bg-gray-700 group">-->
<!--                        <svg class="flex-shrink-0 w-5 h-5 text-gray-500 transition duration-75 dark:text-gray-400 group-hover:text-gray-900 dark:group-hover:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 18 18">-->
<!--                            <path d="M6.143 0H1.857A1.857 1.857 0 0 0 0 1.857v4.286C0 7.169.831 8 1.857 8h4.286A1.857 1.857 0 0 0 8 6.143V1.857A1.857 1.857 0 0 0 6.143 0Zm10 0h-4.286A1.857 1.857 0 0 0 10 1.857v4.286C10 7.169 10.831 8 11.857 8h4.286A1.857 1.857 0 0 0 18 6.143V1.857A1.857 1.857 0 0 0 16.143 0Zm-10 10H1.857A1.857 1.857 0 0 0 0 11.857v4.286C0 17.169.831 18 1.857 18h4.286A1.857 1.857 0 0 0 8 16.143v-4.286A1.857 1.857 0 0 0 6.143 10Zm10 0h-4.286A1.857 1.857 0 0 0 10 11.857v4.286c0 1.026.831 1.857 1.857 1.857h4.286A1.857 1.857 0 0 0 18 16.143v-4.286A1.857 1.857 0 0 0 16.143 10Z"/>-->
<!--                        </svg>-->
<!--                        <span class="flex-1 ml-3 whitespace-nowrap">Kanban</span>-->
<!--                        <span class="inline-flex items-center justify-center px-2 ml-3 text-sm font-medium text-gray-800 bg-gray-100 rounded-full dark:bg-gray-700 dark:text-gray-300">Pro</span>-->
<!--                    </a>-->
<!--                </li>-->
<!--                <li>-->
<!--                    <a href="#" class="flex items-center p-2 text-gray-900 rounded-lg dark:text-white hover:bg-gray-100 dark:hover:bg-gray-700 group">-->
<!--                        <svg class="flex-shrink-0 w-5 h-5 text-gray-500 transition duration-75 dark:text-gray-400 group-hover:text-gray-900 dark:group-hover:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20">-->
<!--                            <path d="m17.418 3.623-.018-.008a6.713 6.713 0 0 0-2.4-.569V2h1a1 1 0 1 0 0-2h-2a1 1 0 0 0-1 1v2H9.89A6.977 6.977 0 0 1 12 8v5h-2V8A5 5 0 1 0 0 8v6a1 1 0 0 0 1 1h8v4a1 1 0 0 0 1 1h2a1 1 0 0 0 1-1v-4h6a1 1 0 0 0 1-1V8a5 5 0 0 0-2.582-4.377ZM6 12H4a1 1 0 0 1 0-2h2a1 1 0 0 1 0 2Z"/>-->
<!--                        </svg>-->
<!--                        <span class="flex-1 ml-3 whitespace-nowrap">Inbox</span>-->
<!--                        <span class="inline-flex items-center justify-center w-3 h-3 p-3 ml-3 text-sm font-medium text-blue-800 bg-blue-100 rounded-full dark:bg-blue-900 dark:text-blue-300">3</span>-->
<!--                    </a>-->
<!--                </li>-->
<!--                <li>-->
<!--                    <a href="#" class="flex items-center p-2 text-gray-900 rounded-lg dark:text-white hover:bg-gray-100 dark:hover:bg-gray-700 group">-->
<!--                        <svg class="flex-shrink-0 w-5 h-5 text-gray-500 transition duration-75 dark:text-gray-400 group-hover:text-gray-900 dark:group-hover:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 18">-->
<!--                            <path d="M14 2a3.963 3.963 0 0 0-1.4.267 6.439 6.439 0 0 1-1.331 6.638A4 4 0 1 0 14 2Zm1 9h-1.264A6.957 6.957 0 0 1 15 15v2a2.97 2.97 0 0 1-.184 1H19a1 1 0 0 0 1-1v-1a5.006 5.006 0 0 0-5-5ZM6.5 9a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9ZM8 10H5a5.006 5.006 0 0 0-5 5v2a1 1 0 0 0 1 1h11a1 1 0 0 0 1-1v-2a5.006 5.006 0 0 0-5-5Z"/>-->
<!--                        </svg>-->
<!--                        <span class="flex-1 ml-3 whitespace-nowrap">Users</span>-->
<!--                    </a>-->
<!--                </li>-->
<!--                <li>-->
<!--                    <a href="#" class="flex items-center p-2 text-gray-900 rounded-lg dark:text-white hover:bg-gray-100 dark:hover:bg-gray-700 group">-->
<!--                        <svg class="flex-shrink-0 w-5 h-5 text-gray-500 transition duration-75 dark:text-gray-400 group-hover:text-gray-900 dark:group-hover:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 18 20">-->
<!--                            <path d="M17 5.923A1 1 0 0 0 16 5h-3V4a4 4 0 1 0-8 0v1H2a1 1 0 0 0-1 .923L.086 17.846A2 2 0 0 0 2.08 20h13.84a2 2 0 0 0 1.994-2.153L17 5.923ZM7 9a1 1 0 0 1-2 0V7h2v2Zm0-5a2 2 0 1 1 4 0v1H7V4Zm6 5a1 1 0 1 1-2 0V7h2v2Z"/>-->
<!--                        </svg>-->
<!--                        <span class="flex-1 ml-3 whitespace-nowrap">Products</span>-->
<!--                    </a>-->
<!--                </li>-->
            </ul>
        </div>
    </aside>
    <div class="pt-20 sm:pl-[256px] bg-white dark:bg-gray-900 min-h-screen pb-20">
        <router-view></router-view>
    </div>
</template>
<script>
import { computed, onMounted, ref, watch } from "vue";
import { initFlowbite } from "flowbite";
import store from "@/store";
import router from "@/router";
import { alertSuccess, makeMoney } from "../../calc";
import { FwbDropdown, FwbListGroup, FwbListGroupItem, FwbButton } from 'flowbite-vue'
import getAxios from "@/axios";
import Echo from "laravel-echo";
import { useRoute } from "vue-router";
import AuthCard from "@/components/cards/AuthCard.vue";
export default {
    name: "AdminLayout", methods: { makeMoney },
    computed: {},
    components: { AuthCard, FwbListGroup, FwbDropdown, FwbListGroupItem, FwbButton},
    setup() {
        const mode = ref()
        const discussionText = ref('');
        const route = useRoute()
        const switchColorMode = () => {
            let mode = localStorage.getItem('color-theme');
            if (mode) {
                if (mode === 'dark') {
                    mode = 'light'
                } else {
                    mode = 'dark'
                }
            } else {
                mode = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'light' : 'dark'
            }
            localStorage.setItem('color-theme', mode)
            setMode()
        }
        const user = computed(() => store.state.user)
        const hasNewComment = ref(false);
        const sendComment = () => {
            getAxios().post('/api/send-comment',{
                text:discussionText.value
            }).then((res) => {
                discussionText.value = ''
                var clickEvent = new MouseEvent("click", {
                    "view": window,
                    "bubbles": true,
                    "cancelable": false
                });
                document.getElementById('notif-dropdown-button').dispatchEvent(clickEvent)
                alertSuccess('Մեկնաբանությունն ավելացված է')

            })
        }

        const logout = () => {
            getAxios().post('/api/auth/logout', {
                token:store.getters.getToken
            }).then((res) => {
                store.dispatch('logout')
                router.push('/login')
            })
        }
        const goToDiscussions = () => {
            hasNewComment.value = false
            router.push('/discussions');
        }
        const setMode = () => {
            let k = '';
            if (localStorage.getItem('color-theme') === 'dark' || (!('color-theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
                k = 'dark'
            } else {
                document.documentElement.classList.remove('dark')
                k = 'light'
            }
            mode.value = k
        }
        onMounted(() => {
            var pusher = new Pusher('d91f83624e0040704d50', {
                cluster: 'mt1'
            });
            var channel = pusher.subscribe('discussion_channel_' + store.state.user.pawnshop_id);
            channel.bind('new-discussion', function(data) {
                if(route.path !== '/discussions' && data.sender_id !== store.state.user.id){
                    hasNewComment.value = true;
                }

            });
            setMode()
            initFlowbite();
        })
        const searchValue = ref('')
        const searchClients= ref([])
        const searchContracts= ref([])
        const searchTimeout = ref()
        const searchActive = ref(false)
        const initSearch = () => {
            if(searchValue.value){
                getAxios().post('/api/main-search',{
                    text:searchValue.value
                }).then((res) => {
                    if(res.data.clients){
                        searchClients.value = res.data.clients
                        searchContracts.value = res.data.contracts
                    }
                })
            }

        }
        const blurSearchInput = () => {
            setTimeout(() => {
                searchActive.value = false
            },200)
        }
        const focusSearchInput = () => {
            searchActive.value = true
        }
        const search = () => {
            searchActive.value = true
            if(searchTimeout.value){
                clearTimeout(searchTimeout.value)
            }
            searchTimeout.value = setTimeout(() => {
                initSearch()
                clearTimeout(searchTimeout.value)
            },500)
        }
        watch(() => searchValue.value,(value) => {
            if(!value){
                searchActive.value = false
            }
        })
        return {
            switchColorMode,
            logout,
            search,
            searchActive,
            searchValue,
            focusSearchInput,
            goToDiscussions,
            hasNewComment,
            blurSearchInput,
            discussionText,
            sendComment,
            searchClients,
            searchContracts,
            user,
            mode
        }
    },
}
</script>


<style scoped>
nav {
    z-index: 1;
    position: fixed;
    width: 100%;
    box-shadow: 0 0 5px 0px #00000054;
}
.notif-pinner{
    position: absolute;
    width: 10px;
    height:10px;
    border-radius: 50%;
    background: red;
    right: -4px;
    top: 0;
}

</style>
