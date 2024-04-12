<template>
    <button id="dropdownAvatarNameButton" data-dropdown-toggle="dropdownAvatarName" class="flex items-center text-sm font-medium text-gray-900 rounded-full hover:text-blue-600 dark:hover:text-blue-500 md:mr-0 dark:text-white" type="button">
        <span class="sr-only">Open user menu</span>
        {{user.name}}
        <svg class="w-2.5 h-2.5 ml-2.5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 10 6">
            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 4 4 4-4"/>
        </svg>
    </button>

    <!-- Dropdown menu -->
    <div id="dropdownAvatarName" class="z-10 hidden bg-white divide-y divide-gray-100 rounded-lg shadow w-44 dark:bg-gray-700 dark:divide-gray-600">
        <div class="px-4 py-3 text-sm text-gray-900 dark:text-white">
            <div class="font-medium truncate">{{user.name + ' ' + user.surname}}</div>
            <div class="truncate dark:text-gray-400">{{user.email}}</div>
            <div class="truncate text-xs">{{user.pawnshop?.city + '/' +user.pawnshop?.address}}</div>
        </div>
        <ul class="py-2 text-sm text-gray-700 dark:text-gray-200" aria-labelledby="dropdownInformdropdownAvatarNameButtonationButton">
            <li v-if="user.role === 'admin'">
                <router-link to="/dashboard" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-600 dark:hover:text-white">Ադմինիստրացիա</router-link>
            </li>
        </ul>
        <div class="py-2">
            <a type="button" @click="logout" class="block cursor-pointer px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:hover:bg-gray-600 dark:text-gray-200 dark:hover:text-white"><i class="fa-solid pr-2 fa-right-from-bracket"></i>Ելք</a>
        </div>
    </div>
</template>
<script>
import store from "@/store";
import { computed } from "vue";
export default {
    name: "AuthCard",
    emits: ['logout'],
    setup(props,ctx){
        const logout = () => {
            ctx.emit("logout");
        }
        const user = computed(() => store.state.user || {})
        return {
            logout,
            user
        }
    }
}
</script>
<style scoped>

</style>
