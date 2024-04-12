<template>
    <div class="container mx-auto p-4 pb-6">
        <div class="flex items-center mb-4">
            <h2 class="text-xl dark:text-white">Հաճախորդի Էջ </h2>
            <span v-if="!pageLoaded" class="pl-2 page-spinner-handler"><fwb-spinner size="6" color="yellow" /></span>
        </div>
        <template v-if="pageLoaded">
            <template v-if="user">
                <div class="grid-cols-12 grid gap-4">
                    <div
                        class="border col-span-4 bg-white dark:bg-gray-800 dark:border-gray-700 border-gray-200 rounded-lg shadow text-gray-900 p-4 dark:text-white">
                        <figure class="w-full">
                            <template v-if="avatar.type === 'image'">
                                <img class="rounded-lg object-cover mx-auto w-full h-48"
                                     :src="avatar.link"
                                     alt="image description">
                            </template>
                            <template v-else>
                                <PDF class="pdf-viewer" :src="avatar.link" v-if="avatar.link"/>
                            </template>
                        </figure>
                        <fwb-button class="w-full my-4" @click="createNewContract">Նոր Պայմանագիր</fwb-button>
                        <div class=" py-4 mx-auto">
                            <div class="grid grid-cols-2 gap-4">
                                <template v-for="field in profileFields">
                                    <div :class="{'col-span-2': field.cols === 2}">
                                        <label :for="'field_' + field.name"
                                               class="block mb-2 text-xs font-light text-gray-600 dark:text-white"><i
                                            :class="'pr-2 fa fa-' + field.icon"></i>{{ field.label }}</label>
                                        <p class="text-xxs">{{ user ? user[field.name] : '' }}</p>
                                    </div>
                                </template>
                            </div>
                        </div>
                        <div v-if="user.files.length" class="border dark:border-gray-700 border-gray-200 rounded-lg shadow p-2">
                            <div class="grid grid-cols-12 gap-4">
                                <div v-for="file in user.files" class="col-span-4">
                                    <div class="overflow-hidden ">
                                        <div class="border file-preview relative rounded-lg overflow-hidden">
                                            <template v-if="isImage(file)">
                                                <img class="profile-file-image " :src="'/storage/client/files/' + file.name" alt="">
                                            </template>
                                            <template v-else>
                                                <div class="profile-file-other">
                                                    <i class="fa fa-file"></i>
                                                </div>
                                            </template>
                                            <div class="download-desk-handler">
                                                <a class="z-10 text-white download-button text-xl" :href="'/storage/client/files/' + file.name" :download="file.original_name"><i class="fa  fa-download"></i></a>
                                            </div>
                                        </div>

                                        <p class="text-sm break-words">{{file.original_name}}</p>
                                    </div>

                                </div>
                            </div>
                        </div>
                        <div class="border-t pt-2 border-gray-800 dark:border-gray-300">
                            <div class="relative">
                                <label class="block mb-2 text-xs font-medium text-gray-900 dark:text-white"><i class="pr-2 fa fa-file"></i>Ֆայլեր</label>
                                <fwb-file-input id="clientFiles" @change="onFilesChanged" multiple />
                            </div>
                            <div class="grid grid-cols-12">
                                <div v-for="(file,idx) in files" :key="file" class="col-span-6 p-4">
                                    <div class="relative rounded-md image-preview-handler border shadow dark:border-gray-700">
                                        <template v-if="isImage(file)">
                                            <img  class="preview-image rounded-md" :src="getImageSrc(file)" alt="">
                                        </template>
                                        <template v-else>
                                            <div class="preview-image">
                                                <span class="preview-file-handler"><i class="fa fa-file"></i></span>
                                            </div>
                                        </template>

                                        <button @click="removeFile(idx)" type="button" class="file-remove-button text-lg absolute left-full top-0 px-1"><i class="text-red-500 fa fa-times"></i></button>
                                    </div>
                                    <p class="text-xs dark:text-gray-300 mt-1">{{file.name}}</p>

                                </div>
                            </div>
                            <fwb-button class="block ml-auto" v-if="files.length" @click="saveFiles" color="green">Պահանել</fwb-button>
                        </div>

                    </div>
                    <div
                        class="border relative col-span-8 bg-white dark:bg-gray-800 dark:border-gray-700 border-gray-200 rounded-lg shadow text-gray-900 p-4 dark:text-white">
                        <div>
                            <template v-if="pageLoading">
                                <span v-if="pageLoading" class="spinner-sign page-spinner-handler"><fwb-spinner size="8" color="yellow" /></span>
                            </template>
                            <template v-else>
                                <fwb-tabs v-model="activeTab" variant="underline" class="p-5">
                                    <fwb-tab name="active" title="Ակտիվ">
                                        <div class="grid-cols-12 grid gap-4 mt-4">
                                            <template v-if="activeContracts.length" v-for="contract in activeContracts">
                                                <div class="col-span-6">
                                                    <router-link :to="'/payments/' + contract.id" class="block hover:bg-gray-200 cursor-pointer dark:hover:bg-gray-700 border rounded-lg dark:bg-gray-800 dark:border-gray-700 shadow text-gray-900 p-4 dark:text-white">
                                                        <i class="fa fa-file-contract mb-4"></i> <span class="text-sm pl-2">{{contract.ADB_ID}}</span>
                                                        <ContractCard :contract="contract"/>
                                                    </router-link>
                                                </div>

                                            </template>
                                            <template v-else>
                                                <div class="col-span-12">
                                                    <p class="text-gray-500 text-sm dark:text-gray-400">Չկան ակտիվ պայմանագրեր</p>
                                                </div>

                                            </template>

                                        </div>
                                    </fwb-tab>
                                    <fwb-tab name="completed" title="Մարված">
                                        <div class="grid-cols-12 grid gap-4 mt-4">
                                            <template v-if="completedContracts.length" v-for="contract in completedContracts">
                                                <div class="col-span-6">
                                                    <router-link :to="'/payments/' + contract.id" class="block border-lime-400 hover:bg-lime-100 cursor-pointer dark:hover:bg-gray-700 border rounded-lg dark:bg-gray-800 shadow text-gray-900 p-4 dark:text-white">
                                                        <i class="fa fa-file-contract mb-4 text-lime-400"></i> <span class="text-sm pl-2">{{contract.ADB_ID}}</span>
                                                        <ContractCard :contract="contract"/>
                                                    </router-link>
                                                </div>

                                            </template>
                                            <template v-else>
                                                <div class="col-span-12">
                                                    <p class="text-gray-500 text-sm dark:text-gray-400">Չկան ավարտված պայմանագրեր</p>
                                                </div>

                                            </template>

                                        </div>
                                    </fwb-tab>
                                    <fwb-tab name="executed" title="Իրացված">
                                        <div class="grid-cols-12 grid gap-4 mt-4">
                                            <template v-if="executedContracts.length" v-for="contract in executedContracts">
                                                <div class="col-span-6">
                                                    <router-link :to="'/payments/' + contract.id" class="block border-blue-600 hover:bg-blue-100 cursor-pointer dark:hover:bg-gray-700 border rounded-lg dark:bg-gray-800 shadow text-gray-900 p-4 dark:text-white">
                                                        <i class="fa fa-file-contract mb-4 text-blue-500"></i> <span class="text-sm pl-2">{{contract.ADB_ID}}</span>
                                                        <ContractCard :contract="contract"/>
                                                    </router-link>
                                                </div>
                                            </template>
                                            <template v-else>
                                                <div class="col-span-12">
                                                    <p class="text-gray-500 text-sm dark:text-gray-400">Չկան իրացված պայմանագրեր</p>
                                                </div>
                                            </template>
                                        </div>
                                    </fwb-tab>
                                </fwb-tabs>
                            </template>
                        </div>
                    </div>
                </div>
            </template>
            <template v-else>
                <div class="bg-white dark:bg-gray-800 rounded-lg p-4 shadow border border-gray-200 dark:border-gray-700 mb-4">
                    <p class="text-gray-800 dark:text-gray-300">Հաճախորդը չի գտնվել</p>
                </div>
            </template>
        </template>

    </div>
</template>
<script>
import getAxios from "../../axios";
import {useRoute} from "vue-router";
import ContractForm from "@/components/contract/ContractForm.vue";
import { computed, onMounted, ref, watch } from "vue";
const profileFields = [
    {icon: 'user', name: 'name', type: 'text', input: 'input', label: 'Անուն', required: true},
    {icon: 'user', name: 'surname', type: 'text', input: 'input', label: 'Ազգանուն', required: true},
    {icon: 'user', name: 'middle_name', type: 'text', input: 'input', label: 'Հայրանուն', required: true},
    {icon: 'passport', name: 'passport', type: 'text', input: 'input', label: 'Անձնագիր', required: true},
    {icon: 'passport', name: 'dob', type: 'text', input: 'input', label: 'Ծնվ․', required: true},
    {icon: 'location-dot', name: 'address', type: 'text', input: 'input', label: 'Հասցե', required: true},
    {icon: 'phone', name: 'phone1', type: 'text', input: 'input', label: 'Հեռախոսահամար 1', required: true},
    {icon: 'phone', name: 'phone2', type: 'text', input: 'input', label: 'Հեռախոսահամար 2', required: true},
    {icon: 'envelope', name: 'email', type: 'email', input: 'input', label: 'Մեյլ', required: true},
    {icon: 'comment', name: 'comment', input: 'textarea', label: 'Այլ', cols: 2, rows: 4},
]

const contractFields = [
    {icon: 'dollar-sign', name: 'worth', type: 'number', input: 'input', label: 'Արժեքը', required: true},
    {icon: 'dollar-sign', name: 'given', type: 'number', input: 'input', label: 'Տրամադրվածը', required: true},
    {icon: 'percent', name: 'rate', type: 'number', input: 'input', label: 'Տոկոսի չափը', required: true},
    {icon: 'bolt', name: 'penalty', type: 'number', input: 'input', label: 'Տուգանքի չափը', required: true},
    {icon: 'money-bill', name: 'one_time_payment', type: 'number', input: 'input', label: 'Միանվագ վճարը'},
    {icon: 'comment', name: 'description', input: 'textarea', label: 'Նկարագրություն', rows: 2},
    {icon: 'text-height', name: 'category_title', input: 'select', label: 'Տեսակը', items: []},
    {icon: 'calendar-days', name: 'deadline', input: 'datetime', label: 'Պայմանագրի ժամկետ', required: true},
]
import { FwbTab, FwbTabs, FwbSpinner, FwbButton, FwbFileInput } from 'flowbite-vue'
import router from "@/router";
import PDF from "pdf-vue3";
import ContractCard from "@/components/cards/ContractCard.vue";
import { alertSuccess } from "@/calc";
export default {
    name: "Profile",
    components: { FwbFileInput, ContractCard, FwbButton, ContractForm, FwbTab, FwbTabs, FwbSpinner, PDF },
    setup() {
        const activeTab = ref('active')
        const route = useRoute()
        const pageLoading = ref(false);
        const pageLoaded = ref(false);
        const files = ref([])
        const user = ref();
        const activeContracts = computed(() => {
            return user.value?.contracts?.filter((v) => v.status === 'initial')
        })
        const completedContracts = computed(() => {
            return user.value?.contracts?.filter((v) => v.status === 'completed')
        })
        const executedContracts = computed(() => {
            return user.value?.contracts?.filter((v) => v.status === 'executed')
        })
        const avatar = computed(() => {
            if(user.value?.files?.length){
                const image = user.value.files.find((v) => isAvatarImage(v))
                if(image){
                    if(image.type === 'application/pdf'){
                        return {
                            type:'pdf',
                            link:'/storage/client/files/' + image.name
                        }
                    }
                    return {
                        type:'image',
                        link:'/storage/client/files/' + image.name
                    }
                }
            }
            return {
                type:'image',
                link:'/files/avatar/default_avatar.jpg'
            }
        })
        const createNewContract = () => {
            localStorage.setItem('client_id',user.value.id)
            router.push('/home')
        }
        const getInfo = () => {
            pageLoading.value = true;
            return getAxios().get('/api/get-clients-info/' + route.params.id)
            .then((res) => {
                pageLoading.value = false;
                user.value = res.data.client
            })
        }
        getInfo().then(() => {
            pageLoaded.value = true
        })
        const isImage = (file) => {
            return file && file.type.split('/')[0] === 'image';
        }
        const isAvatarImage = (file) => {
            return file && (file.type.split('/')[0] === 'image' || file.type === 'application/pdf');
        }
        watch(() => route.params.id,() => {
            getInfo()
        })
        const removeFile = (index) => {
            files.value = files.value.filter((v,i) => i !== index)
        }
        const onFilesChanged = (e) => {
            Array.from(e.target.files).forEach((v) => {
                files.value.push(v)
            })
        }
        const getImageSrc = (file) => {
            if(file){
                return URL.createObjectURL(file)
            }
            return ''
        }
        const saveFiles = () => {
            const formData = new FormData();
            formData.append('client_id', user.value?.id)
            files.value.forEach((v) => {
                formData.append('clientFiles[]', v);
            })
            const headers = { 'Content-Type': 'multipart/form-data'};
            getAxios().post('/api/save-profile-files', formData,{headers}).then((res) => {
                alertSuccess('Ֆայլերը հաջողությամբ պահպանվել են')
                user.value = res.data.client
                files.value = []
            })
        }
        return {
            profileFields,
            pageLoading,
            user,
            activeContracts,
            completedContracts,
            pageLoaded,
            isImage,
            createNewContract,
            activeTab,
            avatar,
            contractFields,
            executedContracts,
            onFilesChanged,
            removeFile,
            files,
            getImageSrc,
            saveFiles
        }
    }
}
</script>

<style scoped lang="scss">
.preview-image{
    width: 100%;
    height:100px;
    object-fit: cover;
}
.preview-file-handler{
    position: absolute;
    left: 50%;
    top: 50%;
    transform:translate(-50%, -50%)
}
.file-preview{
    .profile-file-image{
        height:150px;
        width: 100%;
        object-fit: cover;
    }
    .profile-file-other{
        height:150px;
        i{
            position: absolute;
            left: 50%;
            top:50%;
            transform: translate(-50%, -50%);
        }
    }
    .download-button{
        position: absolute;
        left: 50%;
        top:50%;
        transform: translate(-50%, -50%);
        opacity:0
    }
    .download-desk-handler{
        position:absolute;
        inset: 0;
        transition-duration: 0.2s;

    }
    &:hover{
        .download-desk-handler{
            background: rgba(0,0,0,0.8);
        }
        .download-button{
            opacity: 1;
        }
    }
}

</style>
