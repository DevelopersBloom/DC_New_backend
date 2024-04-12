<template>
    <nav class="bg-white border-gray-200 dark:bg-gray-800">
        <div class="max-w-screen-2xl flex flex-wrap items-center justify-between mx-auto p-4">
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
                <ul class="font-medium flex flex-col p-4 md:p-0 mt-4
        items-center rounded-lg md:flex-row md:space-x-8 md:mt-0 md:border-0">
                    <li>
                        <button @click="switchColorMode" class="text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:focus:ring-gray-700 rounded-lg text-sm p-2.5 w-10 h-10 inline-flex items-center justify-center">
                            <i class="fa fa-moon" v-if="mode==='light'"></i>
                            <i class="fa fa-sun text-white" v-else></i>
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    <div class="pt-20 bg-white dark:bg-gray-900 min-h-screen">
        <div class="container border border-gray-200 dark:border-gray-800 mx-auto max-w-sm bg-white shadow-md dark:bg-gray-800 sm:rounded-lg mt-4 p-4">

            <form @submit="attemptLogin">
                <div class="mb-6">
                    <label for="email" class="block mb-2 text-xs font-medium text-gray-900 dark:text-white">Email</label>
                    <input v-model="form.email" type="email" id="email"
                           :class="[error? 'border-red-500' : 'border-gray-300 dark:border-gray-600']"
                           class="bg-gray-50 border text-gray-900 text-xs rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500" placeholder="name@flowbite.com" required>
                    <span v-if="error" class="text-red-500 text-xs">Սխալ տվյալներ</span>
                </div>
                <div class="mb-6">
                    <label for="password" class="block mb-2 text-xs font-medium text-gray-900 dark:text-white">Գաղտնաբառը</label>
                    <input type="password" v-model="form.password" id="password"
                           :class="[error? 'border-red-500' : 'border-gray-300 dark:border-gray-600']"
                           class="bg-gray-50 border text-gray-900 text-xs rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500" required>
                </div>
                <button type="submit" class="text-white bg-blue-700 hover:bg-blue-800 focus:ring-4 focus:outline-none focus:ring-blue-300 font-medium rounded-lg text-xs w-full sm:w-auto px-5 py-2.5 text-center dark:bg-blue-600 dark:hover:bg-blue-700 dark:focus:ring-blue-800">Մուտք</button>
            </form>

        </div>
    </div>
</template>
<script>
import {onMounted, reactive, ref} from "vue";
import { initFlowbite } from "flowbite";
import store from "@/store";
import router from "@/router";
import getAxios from "@/axios";

export default {
    name: "Layout",
    computed: {},
    setup() {
        const mode = ref()
        const error = ref(false)
        const form = reactive({
            email: '',
            password: ''
        })
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
        const attemptLogin = (e) => {
            e.preventDefault()
                getAxios().post('/api/auth/login', {
                    email:form.email,
                    password: form.password
                }).then((res) => {
                    if(res.data.success === 'success'){
                        error.value = false
                        store.dispatch('login',{token: res.data.access_token, user:res.data.user})
                        router.push('list')
                    }else{
                        error.value = true
                    }

                })
        }
        onMounted(() => {
            setMode()
        })
        return {
            switchColorMode,
            form,
            attemptLogin,
            error,
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

</style>
