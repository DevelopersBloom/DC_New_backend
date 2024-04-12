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
                    <div class="pl-0.5 2xl:pl-2">
                        <div class="relative">
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
                                       class="block w-full p-2 pl-10 text-sm text-gray-900 border border-gray-300 rounded-lg
                       bg-gray-50 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700
                       dark:border-gray-600 dark:placeholder-gray-400 dark:text-white
                       dark:focus:ring-blue-500 dark:focus:border-blue-500" placeholder="Փնտրել հաճախորդ, պայմ․" required>
                            </div>
                            <div class="absolute mt-1 top-full z-10 left-0 right-0 bg-gray-100 dark:bg-gray-900 rounded-lg shadow border border-gray-200 dark:border-gray-700" v-if="searchActive && searchValue">
                                <dl class="text-gray-900 divide-y divide-gray-200 dark:text-white dark:divide-gray-700 p-4">
                                    <h3 class="text-gray-500 text-sm dark:text-gray-400 mb-2">Հաճախորդ</h3>
                                    <template v-if="searchClients.length">
                                        <div v-for="value in searchClients">
                                            <router-link @click="searchValueClicked" :to="'/profile/' + value.id">
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
                                            <router-link @click="searchValueClicked" :to="'/payments/' + value.id">
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
                    <ul class="font-medium flex flex-col p-4 md:p-0 mt-4
        items-center rounded-lg xl:flex-row md:space-x-2 md:mt-0 md:border-0">
<!--                        <li>-->
<!--                            <fwb-button @click="downloadExcel">Excel</fwb-button>-->
<!--                        </li>-->
                        <li>
                            <router-link to="/todays-list" class="block py-2 2xl:px-3 px-1 text-gray-900 dark:text-gray-300 rounded text-xs">
                                Այսօրվա Վճարումներ
                            </router-link>
                        </li>
                        <li>
                            <router-link to="/list" class="block py-2 2xl:px-3 px-1 text-gray-900 dark:text-gray-300 rounded text-xs">
                                Պայմանագրերի ցուցակ
                            </router-link>
                        </li>
                        <li>
                            <router-link to="/users" class="block py-2 2xl:px-3 px-1 text-gray-900 dark:text-gray-300 rounded text-xs">
                                Հաճախորդների ցուցակ
                            </router-link>
                        </li>

                        <li>
                            <router-link to="/home" class="block rounded-full text-xs bg-blue-600 hover:bg-blue-700 py-2 font-semibold px-4 text-white new_contract">+
                                Նոր պայմանագիր
                            </router-link>
                        </li>
                        <li>
                            <div class="relative py-2">
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
    <div class="pt-20 bg-white dark:bg-gray-900 min-h-screen pb-20">
        <router-view></router-view>
    </div>
    <div class="fixed bottom-0 left-0 z-50 w-full h-12 bg-white border-t border-gray-200 dark:bg-gray-700 dark:border-gray-600">
        <div class="h-full flex items-center justify-end p-4">
            <div class="flex gap-3">
                <fwb-button color="green" size="sm" tag="router-link" href="/costs" class="new_contract" outline>Ծախսեր</fwb-button>
                <fwb-button color="green" size="sm" tag="router-link" href="/cashbox" class="new_contract">Դրամարկղ</fwb-button>
            </div>

        </div>
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
        const downloadExcel = () => {
            // const downloadUrl = '/api/download-quarter-export';
            // window.open(downloadUrl, '_blank');
            getAxios().post('/api/download-quarter-export',{})
            .then(response => {
                const url = window.URL.createObjectURL(new Blob([response.data]));
                const link = document.createElement('a');
                link.href = url;
                link.setAttribute('download', 'users.xlsx');
                document.body.appendChild(link);
                link.click();
                link.remove()
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
        const pusher = ref();
        const discussionChannel = ref();
        const discountChannel = ref();
        onMounted(() => {
            pusher.value = new Pusher('d91f83624e0040704d50', {
                cluster: 'mt1'
            });
            discussionChannel.value = pusher.value.subscribe('discussion_channel_' + store.state.user?.pawnshop_id);
            discussionChannel.value.bind('new-discussion', function(data) {
                if(route.path !== '/discussions' && data.sender_id !== store.state.user?.id){
                    hasNewComment.value = true;
                }

            });
            discountChannel.value = pusher.value.subscribe('discount_channel_' + store.state.user?.pawnshop_id);
            discountChannel.value.bind('new-discount-response', function(data) {
                if(data.sender_id !== store.state.user?.id){
                    alertSuccess('Զեղչը հաստատված է',100000,'/payments/' + data.contract_id)
                }

            });
            setMode()
            initFlowbite();
        })
        onUnmounted(() => {
            pusher.value.unsubscribe('discussion_channel_' + store.state.user?.pawnshop_id)
            pusher.value.unsubscribe('discount_channel_' + store.state.user?.pawnshop_id)
        })
        const searchValueClicked = () => {
            searchActive.value = false
        }
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
        const blurSearchInput = (e) => {
            if(e.relatedTarget === null){
                setTimeout(() => {
                    searchActive.value = false
                },200)
            }

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
            mode,
            downloadExcel,
            searchValueClicked
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
