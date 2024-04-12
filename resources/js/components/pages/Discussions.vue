<template>
    <div class="container mx-auto pt-10 p-4 pb-6">
        <div class="flex items-center">
            <h3 class="text-lg dark:text-white">Քննարկումներ</h3>
            <span v-if="pageLoading" class="pl-2 page-spinner-handler"><fwb-spinner size="6" color="yellow" /></span>
        </div>
        <div class="border mt-4 bg-white dark:bg-gray-800 dark:border-gray-700 border-gray-200 rounded-lg shadow text-gray-900 p-4 dark:text-white">
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                <template v-if="pageAlreadyLoaded">
                    <template v-if="discussions.length">
                        <div v-for="discussion in discussions"
                             class="block max-w-sm p-4 bg-rose-100  border border-gray-200 rounded-lg shadow  dark:border-gray-700">
                            <div class="flex mb-2 items-center justify-between">
                                <h5 class=" text-lg font-bold tracking-tight text-gray-900">{{discussion.user.name}}</h5>
<!--                                <span class="text-gray-900 font-semibold text-xs">{{setTime(discussion.created_at)}}</span>-->
                                <span class="text-gray-900 font-semibold text-xs">{{discussion.date}}</span>
                            </div>
                            <p class="font-normal text-gray-700 text-md"><i class="fa text-sm fa-comment pr-1"></i>{{discussion.text}}</p>
                        </div>
                    </template>
                    <template v-else>
                        <h5 class="mb-2 text-sm tracking-tight text-gray-900 dark:text-white">Քննարկումներ չեն գտնվել</h5>
                    </template>
                </template>
            </div>
        </div>

    </div>
</template>
<script>
import getAxios from "@/axios";
import { onMounted, ref } from "vue";
import { FwbSpinner } from 'flowbite-vue'
import store from "@/store";
export default {
    name: "Discussions",
    components: {FwbSpinner},
    setup (){
        const discussions = ref([])
        const pageLoading = ref(false);
        const pageAlreadyLoaded = ref(false);
        const getComments = () => {
            pageLoading.value = true
            return getAxios().get('/api/get-comments').then((res) => {
                pageLoading.value = false
                discussions.value = res.data.discussions
            })
        }
        const setTime = (date) => {
            date = new Date(date)
            let options = {
                year: "numeric",
                month: "numeric",
                day: "numeric",
                hour: "numeric",
                minute: "numeric",
                second: "numeric",
                hour12: false,
                timeZone: "Asia/Yerevan",
            };
            return new Intl.DateTimeFormat("en-US", options).format(date)
        }
        getComments().then(() => {
            pageAlreadyLoaded.value = true
        })
        onMounted(() => {
            var pusher = new Pusher('d91f83624e0040704d50', {
                cluster: 'mt1'
            });
            var channel = pusher.subscribe('discussion_channel_' + store.state.user.pawnshop_id);
            channel.bind('new-discussion', function(data) {
                getComments()
            });
        })
        return {
            discussions,
            setTime,
            pageLoading,
            pageAlreadyLoaded
        }
    }
}
</script>

<style scoped>

</style>
