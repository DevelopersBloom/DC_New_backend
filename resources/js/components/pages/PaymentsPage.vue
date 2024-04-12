<template>
    <span v-if="loadingRequest" class="page-spinner-handler"><fwb-spinner size="6" color="yellow" /></span>
    <fwb-modal v-if="showExtendModal" @close="closeModal">
        <template #header>
            <div class="flex items-center text-lg dark:text-gray-400">
                Պայմանգրի Երկարացում
            </div>
        </template>
        <template #body>
            <div class="flex justify-start w-min gap-4 mb-3">
                <fwb-radio v-model="extendContractForm.deadline_type" label="Օրեր" value="days" />
                <fwb-radio v-model="extendContractForm.deadline_type" label="Ամիսներ" value="months" />
                <fwb-radio v-model="extendContractForm.deadline_type" label="Օրացույց" value="calendar" />
            </div>
            <template v-if="extendContractForm.deadline_type === 'days'">
                <div class="flex items-center gap-4">
                    <input v-model="extendContractForm.deadline_days" type="number" step="1" id="deadline_days" class="block p-2 text-gray-900 border border-gray-300 rounded-lg bg-gray-50 sm:text-xs focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                    <span class="dark:text-white">Օր</span>
                </div>
            </template>
            <template v-else-if="extendContractForm.deadline_type === 'months'">
                <div class="flex items-center gap-4">
                    <input v-model="extendContractForm.deadline_months" type="number" step="1" id="deadline_months" class="block p-2 text-gray-900 border border-gray-300 rounded-lg bg-gray-50 sm:text-xs focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                    <span class="dark:text-white">Ամիս</span>
                </div>
            </template>
            <template v-else-if="extendContractForm.deadline_type === 'calendar'">
                <datepicker required type="date" id="field_deadline"
                            v-model:value="extendContractForm.deadline"></datepicker>
            </template>
        </template>
        <template #footer>
            <div class="flex justify-between">
                <fwb-button @click="closeModal" color="alternative">
                    Չեղարկել
                </fwb-button>
                <fwb-button @click="extendContract" color="green">
                    Երկարացնել
                </fwb-button>
            </div>
        </template>
    </fwb-modal>
    <div class="overflow-x-auto overflow-y-visible sm:rounded-lg container mx-auto pt-10 p-4">
        <div class="flex items-center mb-4 gap-2">
            <h2 class="text-xl dark:text-white">Պայմանագրի Էջ </h2>
            <fwb-dropdown text="Bottom" class="" v-if="contract?.status === 'initial'">
                <template #trigger>
                    <fwb-button size="xs" color="alternative" ><i class="fa fa-ellipsis"></i></fwb-button>
                </template>
                <div class="py-2 px-4 border dark:border-gray-500" >
                    <router-link v-if="contract?.status === 'initial' && user.role === 'admin'" class="text-sm"  :to="'/edit-contract/' + contract.id"><div class="flex items-center dark:text-white hover:underline"><i class="fa fa-edit pr-2"></i><span>Փոփոխել</span></div></router-link>
                    <button @click="() => showExtendModal = true"><span class="flex text-sm items-center dark:text-white hover:underline"><i class="fa fa-calendar pr-2"></i> <span>Երկարացնել</span> </span></button>
                </div>
            </fwb-dropdown>
            <fwb-button @click="downloadContract" size="sm"><i class="fa fa-download pr-2"></i>Պայմանագիր</fwb-button>
            <fwb-button @click="downloadBond" size="sm" color="yellow"><i class="fa fa-download pr-2"></i>Գրավատոմս</fwb-button>
            <span v-if="!pageLoaded" class="pl-2 page-spinner-handler"><fwb-spinner size="6" color="yellow" /></span>
        </div>
        <template v-if="pageLoaded">
            <template v-if="contract">
                <div class="grid grid-cols-12 gap-4">
                    <section class="bg-white col-span-7 dark:bg-gray-800 rounded-lg p-4 shadow border mb-4"
                             :class="{'border-gray-200 dark:border-gray-700':contract.status === 'initial',
                             'border-lime-300':contract.status === 'completed',
                             'border-blue-500':contract.status === 'executed'
                             }"
                    >
                        <h3 class="text-gray-500 text-sm dark:text-gray-400 mb-2">
                            Պատմություն
                            <template v-if="contract.status === 'completed'">
                                <span class="pl-2"> <i class="fa fa-check dark:text-lime-400 mr-2 text-green-500"></i> </span>
                                <span class="dark:text-lime-400 text-green-500">Մարված</span>
                            </template>
                            <template v-else-if="contract.status === 'executed'">
                                <span class="pl-2"> <i class="fa fa-check text-blue-600 mr-2"></i> </span>
                                <span class="text-blue-600">Իրացված</span>
                            </template>

                        </h3>
                        <div class="relative overflow-x-auto sm:rounded-lg">
                            <table class="w-full text-xs md:text-sm text-left text-gray-500 dark:text-gray-400">
                                <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
                                <tr>
                                    <th scope="col" class="px-4 py-2">
                                        Տեսակը
                                    </th>
                                    <th scope="col" class="px-4 py-2">
                                        Ամսաթիվ
                                    </th>
                                    <th scope="col" class="px-4 py-2">
                                        Գումար
                                    </th>
                                    <th scope="col" class="px-4 py-2">
                                        Օգտատեր
                                    </th>
                                    <th scope="col" class="px-4 py-2">
                                        Օրդեր
                                    </th>

                                </tr>
                                </thead>
                                <tbody>
                                <tr v-for="(history, index) in contract.history"
                                    :class="[index%2 === 0 ? 'bg-white border-b dark:bg-gray-900 dark:border-gray-700' : 'border-b bg-gray-50 dark:bg-gray-800 dark:border-gray-700']">
                                    <th scope="row" class="px-4 py-3 font-medium text-gray-900 whitespace-nowrap dark:text-white">
                                        {{history.type.title}}
                                    </th>
                                    <td class="px-4 py-3">
                                        {{history.date}}
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="font-bold text-blue-600" v-if="history.amount">{{makeMoney(history.amount,true)}}</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        {{history.user.name + ' ' + history.user.surname}}
                                    </td>
                                    <td class="px-4 py-3">
                                        <template v-if="history.order">
                                            <fwb-button @click="() => downloadOrder(history.order)" size="xs"><i class="fa fa-download pr-2"></i>{{history.order.title}}</fwb-button>
                                        </template>
                                    </td>
                                </tr>
                                </tbody>
                            </table>
                        </div>

                    </section>
                    <section v-if="discounts.length" class="bg-white col-span-5 dark:bg-gray-800 rounded-lg p-4 shadow border border-gray-200 dark:border-gray-700 mb-4">
                        <h3 class="text-gray-500 text-sm dark:text-gray-400 mb-2">Զեղչի հարցում</h3>
                        <div class="relative overflow-x-auto sm:rounded-lg">
                            <table class="w-full text-xs md:text-sm text-left text-gray-500 dark:text-gray-400">
                                <thead class="text-xs text-gray-700 uppercase text-center bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
                                <tr>
                                    <th scope="col" class="px-4 py-2">
                                        Գումար
                                    </th>
                                    <th scope="col" class="px-4 py-2">
                                        Կարգավիճակ
                                    </th>
                                    <th v-if="user.role === 'admin'" scope="col" class="px-6 py-3">
                                        Պատասխան
                                    </th>
                                </tr>
                                </thead>
                                <tbody>
                                <template v-for="(discount, index) in discounts">
                                    <tr
                                        :class="[index%2 === 0 ? 'bg-white border-b dark:bg-gray-900 dark:border-gray-700' : 'border-b bg-gray-50 dark:bg-gray-800 dark:border-gray-700']">
                                        <td class="px-4 py-2 text-center">
                                            {{makeMoney(discount.amount,true)}}
                                        </td>
                                        <td class="px-4 py-2">
                                            <template v-if="discount.status === 'initial'">
                                                <div class="text-center">-</div>
                                            </template>
                                            <template v-else-if="discount.status === 'accepted'">
                                                <div class="text-center text-lime-500">Ընդունված</div>
                                            </template>
                                            <template v-else-if="discount.status === 'rejected'">
                                                <div class="text-center text-red-500">Մերժված</div>
                                            </template>
                                        </td>
                                        <td v-if="user.role === 'admin'" class="px-6 py-4">
                                            <div class="flex gap-2 mx-auto w-max items-start" v-if="discount.status === 'initial'">
                                                <button @click="answerDiscount(discount.id,'accept')" class="transition-all font-semibold relative bg-green-700 hover:bg-green-800 text-white rounded-lg px-3 py-1 whitespace-nowrap text-xs">Ընդունել</button>
                                                <button @click="answerDiscount(discount.id,'reject')" class="transition-all font-semibold relative bg-red-700 hover:bg-red-800 text-white rounded-lg px-3 py-1 whitespace-nowrap text-xs">Մերժել</button>
                                            </div>
                                        </td>
                                    </tr>
                                </template>

                                </tbody>
                            </table>
                        </div>

                    </section>
                </div>

                <div class="">
                    <payment v-model="contract"/>
                    <div class="mt-4">
                        <contract-form :contract="contract"  :editable="false" />
                    </div>


                </div>
            </template>
            <template v-else>
                <div class="bg-white dark:bg-gray-800 rounded-lg p-4 shadow border border-gray-200 dark:border-gray-700 mb-4">
                    <p class="text-gray-800 dark:text-gray-300">Պայմանագիրը չի գտնվել</p>
                </div>
            </template>
        </template>

    </div>
</template>

<script>
import getAxios from "../../axios"
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import {useRoute} from "vue-router";
import Payment from "../contract/Payment.vue";
import ContractForm from "../contract/ContractForm.vue";
import { alertError, alertSuccess, makeMoney } from "@/calc";
import { FwbSpinner, FwbButton, FwbDropdown, FwbModal,  FwbRadio } from 'flowbite-vue'
import store from "@/store";
export default {
    name: "PaymentsPage",
    components: {ContractForm, Payment, FwbSpinner, FwbButton, FwbDropdown, FwbModal, FwbRadio},
    setup() {
        const route = useRoute()
        const contract = ref({});
        const pageLoaded = ref(false);
        const loadingRequest = ref(false);
        const showExtendModal = ref(false)
        const closeModal =  () => {
            showExtendModal.value = false
        }

        const extendContractForm = ref({
            deadline: new Date(),
            deadline_type: 'days',
            deadline_days: 10,
            deadline_months: 5,
        })
        const downloadContract = async () => {
            const downloadUrl = `/api/download-contract/${contract.value?.id}`;
            window.open(downloadUrl, '_blank');
        }
        const downloadBond = async () => {
            const downloadUrl = `/api/download-bond/${contract.value?.id}`;
            window.open(downloadUrl, '_blank');
        }
        const downloadOrder = async (order) => {
            const downloadUrl = '/api/download-order/'+ order.id;
            window.open(downloadUrl, '_blank');
        }
        const extendContract = () => {
            showExtendModal.value = false
            loadingRequest.value = true
            getAxios().post('/api/extend-contract',{...extendContractForm.value,contract_id:contract.value.id}).then((res) => {
                loadingRequest.value = false
                if(res.data.success === 'success'){
                    contract.value = res.data.contract
                    alertSuccess('Պայմանագիրը հաջողությամբ երկարացվել է');
                }else{
                    alertError('Ինչ որ բան այնպես չէ');
                }
            }).catch((e) => {
                loadingRequest.value = false
                alertError('Ինչ որ բան այնպես չէ')
            })
        }
        const discounts = computed(() => {
            return contract.value?.discounts
        })
        const answerDiscount = (id,answer) => {
            loadingRequest.value = true
            getAxios().post('/api/answer-discount',{id,answer}).then((res) => {
                loadingRequest.value = false
                if(res.data.success === 'success'){
                    contract.value = res.data.contract
                }else{
                    alertError('Ինչ որ բան այնպես չէ');
                }
            }).catch((e) => {
                loadingRequest.value = false
                alertError('Ինչ որ բան այնպես չէ')
            })
        }
        const getInfo = () => {
            return getAxios().get('/api/get-payments/' + route.params.id)
            .then((res) => {
                contract.value = res.data.contract
                if(contract.value){
                    extendContractForm.value.deadline = new Date(contract.value.deadline)
                }

                return res
            })
        }
        setInterval(() => {
            getInfo()
        },60*60*1000)
        const pusher = ref();
        const channel = ref();
        onMounted(() => {
            pusher.value = new Pusher('d91f83624e0040704d50', {
                cluster: 'mt1'
            });
            channel.value = pusher.value.subscribe('discount_channel_' + store.state.user?.pawnshop_id);
            channel.value.bind('new-discount-response', function(data) {
                if(data.sender_id !== store.state.user?.id && data.contract_id === contract.value?.id){
                    getInfo()
                }

            });
        })
        onUnmounted(() => {
            pusher.value.unsubscribe('discount_channel_' + store.state.user?.pawnshop_id)
            // channel.value.unbind('new-discount-response')
        })
        const user = computed(() => store.state.user || {})
        getInfo().then(() => {
            pageLoaded.value = true
        })
        watch(() => route.params.id,() => {
            getInfo()
        })

        return {
            contract,
            pageLoaded,
            user,
            makeMoney,
            closeModal,
            extendContract,
            loadingRequest,
            extendContractForm,
            showExtendModal,
            answerDiscount,
            discounts,
            downloadContract,
            downloadBond,
            downloadOrder,
        }
    }
}
</script>


<style scoped>

</style>
