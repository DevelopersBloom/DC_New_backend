<template>
    <nav class="bg-white border-gray-200 dark:bg-gray-800">
        <div class="max-w-screen-2xl flex flex-wrap justify-between mx-auto p-4 gap-3">
            <router-link to="/" class="flex items-center h-fit">
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
            <button data-collapse-toggle="navbar-default" type="button"
                    class="order-0 md:order-1 inline-flex items-center p-2 w-10 h-10 justify-center text-sm text-gray-500
              rounded-lg xl:hidden hover:bg-gray-100 focus:outline-none focus:ring-2
              focus:ring-gray-200 dark:text-gray-400 dark:hover:bg-gray-700 dark:focus:ring-gray-600"
                    aria-controls="navbar-default" aria-expanded="false">
                <span class="sr-only">Open main menu</span>
                <svg class="w-5 h-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 17 14">
                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M1 1h15M1 7h15M1 13h15"/>
                </svg>
            </button>
            <div class="hidden w-full grow xl:block md:w-auto" id="navbar-default">
                <div class="flex flex-col xl:flex-row justify-between grow">
                    <div></div>
                    <ul class="font-medium flex flex-col p-4 md:p-0 mt-4
        items-center rounded-lg xl:flex-row md:space-x-3 md:mt-0 md:border-0">
                        <li>
                            <button @click="switchColorMode" class="text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:focus:ring-gray-700 rounded-lg text-sm p-2 w-10 h-10 inline-flex items-center justify-center">
                                <i class="fa fa-moon" v-if="mode==='light'"></i>
                                <i class="fa fa-sun text-white" v-else></i>
                            </button>
                        </li>
                        <li v-if="user">
                            <auth-card @logout="logout"></auth-card>
                        </li>
                    </ul>
                </div>


            </div>
        </div>
    </nav>
    <div class="pt-5 bg-white dark:bg-gray-900 min-h-screen pb-20">
        <router-view></router-view>
    </div>
</template>
<script>
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
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
    name: "Layout", methods: { makeMoney },
    computed: {},
    components: { AuthCard, FwbListGroup, FwbDropdown, FwbListGroupItem, FwbButton},
    setup() {
        const mode = ref()
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
        const logout = () => {
            getAxios().post('/api/auth/logout', {
                token:store.getters.getToken
            }).then((res) => {
                store.dispatch('logout')
                router.push('/login')
            })
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
            initFlowbite()
            setMode()
        })
        return {
            switchColorMode,
            logout,
            user,
            mode,
        }
    },
}
</script>


<style scoped>

</style>
