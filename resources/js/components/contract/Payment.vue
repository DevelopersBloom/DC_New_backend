<template>
    <span v-if="loadingPayment" class="pl-2 page-spinner-handler"><fwb-spinner size="6" color="yellow" /></span>
    <confirm-modal
        :show="showConfirmModal"
        :anotherPayment="canHaveAnotherAmount"
        @submit="submitConfirmationModal"
        @close="() => showConfirmModal = false"
        :amount="modalAmount"
        :penalty="penaltyLeft"
        :type="modalType"
        :debt="debtPayments"
    />
    <payment-modal
        :show="showPaymentModal"
        :anotherPayment="canHaveAnotherAmount"
        @submit="submitConfirmationModal"
        @close="() => showPaymentModal = false"
        :amount="modalAmount"
        :penalty="penaltyLeft"
        :type="modalType"
        :debt="debtPayments"
    />
    <fwb-modal v-if="showDiscountModal" @close="() => showDiscountModal = false">
        <template #header>
            <div class="flex items-center text-lg dark:text-gray-400">
                Կիրառել զեղչ
            </div>
        </template>
        <template #body>
            <div>
                <table class="text-xs text-left w-full text-gray-500 dark:text-gray-400 payments-table">
                    <thead class="text-xs text-gray-700 uppercase bg-gray-100 dark:bg-gray-800 dark:text-gray-400">
                    <tr>
                        <th scope="col" class="px-2 py-1">Գումար</th>
                        <th scope="col" class="px-2 py-1">Զեղչ</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr class="border-b dark:border-gray-900 cursor-pointer">
                        <td class="px-2 py-2 dark:text-white">{{makeMoney(fullPenalty - discountsSum,true)}}</td>
                        <td class="px-2 py-2"><fwb-input v-model="discountAmount" type="number" size="sm"></fwb-input></td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </template>
        <template #footer>
            <div class="flex justify-between">
                <fwb-button @click="() => showDiscountModal = false" color="alternative">
                    Չեղարկել
                </fwb-button>
                <fwb-button @click="requestDiscount" color="green">
                    Հարցում
                </fwb-button>
            </div>
        </template>
    </fwb-modal>

    <fwb-modal v-if="showExecutionModal" @close="() => showExecutionModal = false">
        <template #header>
            <div class="flex items-center text-lg dark:text-gray-400">
                Իրացում
            </div>
        </template>
        <template #body>
            <div>
                <p class="dark:text-white">Դուք հաստատում եք <span class="text-red-600">{{makeMoney(executionPaymentAmount,true)}}</span> իրացումը </p>
                <table class="text-xs text-left w-full text-gray-500 dark:text-gray-400 payments-table mt-2">
                    <thead class="text-xs text-gray-700 uppercase bg-gray-100 dark:bg-gray-800 dark:text-gray-400">
                    <tr>
                        <th scope="col" class="px-2 py-1">Արժեքը</th>
                        <th scope="col" class="px-2 py-1">Տրամադրվածը</th>
                        <th scope="col" class="px-2 py-1">Վճարված</th>
                        <th scope="col" class="px-2 py-1">Իրացում</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr class="border-b dark:border-gray-900 cursor-pointer">
                        <td class="px-2 py-2 dark:text-white">{{makeMoney(contract.worth,true)}}</td>
                        <td class="px-2 py-2 dark:text-white">{{makeMoney(contract.given,true)}}</td>
                        <td class="px-2 py-2 dark:text-white">{{makeMoney(contract.collected,true)}}</td>
                        <td class="px-2 py-2 dark:text-white">{{makeMoney(executionPaymentAmount,true)}}</td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </template>
        <template #footer>
            <div class="flex justify-between">
                <fwb-button @click="() => showExecutionModal = false" color="alternative">
                    Չեղարկել
                </fwb-button>
                <fwb-button @click="executeContract" color="green">
                    Իրացնել
                </fwb-button>
            </div>
        </template>
    </fwb-modal>
    <div>
        <div class="grid grid-cols-12 gap-4">
            <div v-if="fullPenalty && !mini"
                 :class="[penaltyLeft ? 'border-red-700' : 'border-gray-200 dark:border-gray-700']"
                 ref="penaltySectionRef"
                 class="border col-span-7 bg-white dark:bg-gray-800  overflow-x-auto rounded-lg shadow text-gray-900 p-4 dark:text-white mb-4" >
                <div class="my-2" >
                    <p class="text-red-600">Տուգանք</p>
                    <div class="flex py-4 flex-col justify-between h-full overflow-x-auto">
                        <table class=" text-left dark:text-gray-400 payments-table">
                            <thead class="text-xs text-gray-700 text-center uppercase bg-gray-100 dark:bg-gray-700 dark:text-gray-400">
                            <tr>
                                <th scope="col" class="px-2 py-1">
                                    Կուտակված
                                </th>
                                <th scope="col" class="px-2 py-1">
                                    Զեղչ/Մարված
                                </th>
                                <th scope="col" class="px-2 py-1">
                                    Կիրառել Զեղչ
                                </th>
                                <th scope="col" class="px-2 py-1">
                                    Վերջնական
                                </th>
                            </tr>
                            </thead>
                            <tbody>
                            <tr class="bg-white border-b dark:bg-gray-800 dark:border-gray-700">
                                <td class="px-2 py-2 text-center">{{makeMoney(fullPenalty,true)}} <Dram/></td>
                                <td class="px-2 py-2 text-center">{{makeMoney(discountsSum,true)}} <Dram/></td>
                                <td class="px-2 py-2"><fwb-button :disabled="!penaltyLeft || !(contract.status === 'initial')" @click="openDiscountModal()" color="red" size="xs" class="mx-auto block">Զեղչ</fwb-button></td>
                                <td class="px-2 py-2 text-center">
                                    {{makeMoney(penaltyLeft,true)}} <Dram/>
                                </td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>


        <div class="border bg-white dark:bg-gray-800 overflow-x-auto
    rounded-lg shadow text-gray-900 p-4 dark:text-white"
             :class="[contract.status === 'completed' ? 'border-lime-300' : 'dark:border-gray-700 border-gray-200',{'w-full': !mini}]">

            <div class="flex flex-col justify-between h-full overflow-x-auto">
                <table class="text-xs text-left w-full text-gray-500 dark:text-gray-400 payments-table">
                    <thead class="text-xs text-gray-700 uppercase bg-gray-100 dark:bg-gray-700 dark:text-gray-400">
                    <tr>
                        <th scope="col" class="px-2 py-1" v-if="!mini">
                        </th>
                        <th scope="col" class="px-2 py-1">
                            Ամսաթիվ
                        </th>
                        <th scope="col" class="px-2 py-1">
                            Վճարող
                        </th>
                        <th v-if="hasAnotherPayer" scope="col" class="px-2 py-1">
                            Հեռ․
                        </th>
                        <th scope="col" class="px-2 py-1">
                            Տեսակ
                        </th>
                        <th scope="col" class="px-2 py-1">
                            Վճ․Տեսակ
                        </th>
                        <th scope="col" class="px-2 py-1">
                            Տոկոս
                        </th>
                        <th scope="col" class="px-2 py-1">
                            Վճարված
                        </th>
                        <th scope="col" class="px-2 py-1">
                            Մայր Գումար
                        </th>
                        <th scope="col" class="px-2 py-1">
                            Գումար
                        </th>
                        <th v-if="false" scope="col" class="px-2 py-1 text-center">
                            Վճարել
                        </th>
                        <th scope="col" class="px-2 py-1 text-center">
                            Կարգ
                        </th>
                    </tr>
                    </thead>
                    <tbody>
                    <template v-for="(payment, index) in payments">
                        <tr class="border-b  dark:border-gray-700 cursor-pointer"
                            :class="{
                        'bg-red-100 dark:bg-blue-900': payment.passed && !payment.selected && !contract.completed && !payment.completed,
                        'bg-gray-200 dark:bg-gray-700': !!payment.selected,
                        'bg-white dark:bg-gray-800':!payment.selected && !payment.passed,
                        'hover:bg-gray-100 dark:hover:bg-gray-700': payment.initial && !payment.selected,
                    }">
                            <td class="px-2 py-2 text-center" v-if="!mini"
                                @click="payment.initial && selectPayment(index) && !mini">
                                <input v-if="payment.status === 'initial'" id="default-checkbox" type="checkbox"
                                       :checked="payment.selected"
                                       class="w-4 h-4 text-blue-600 cursor-pointer bg-gray-100 border-gray-300 rounded focus:ring-blue-500 dark:focus:ring-blue-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600">
                            </td>
                            <th @click="payment.status === 'initial' && !mini && selectPayment(index)" scope="row"
                                class="px-2 py-2 font-medium whitespace-nowrap text-gray-900 dark:text-white"
                            >
                                {{ payment.date }}
                            </th>
                            <td class="px-2 py-2" @click="payment.status === 'initial' && !mini && selectPayment(index)">
                                <template v-if="payment.completed">
                                    <template v-if="payment.anotherPayer"><span class="text-orange-400">{{payment.name + ' ' + payment.surname}}</span></template>
                                    <template v-else>{{contract.name + ' ' + contract.surname}}</template>
                                </template>
                            </td>
                            <td v-if="hasAnotherPayer" class="px-2 py-2" @click="payment.status === 'initial' && !mini && selectPayment(index)">
                                <template v-if="payment.completed">
                                    <template v-if="payment.anotherPayer"><span class="text-orange-400">{{payment.phone}}</span></template>
                                </template>
                            </td>
                            <td @click="payment.status === 'initial' && !mini && selectPayment(index)">
                                <span :class="{'text-red-600':payment.passed && payment.initial}" v-if="payment.type === 'regular'">Հերթական</span>
                                <span class="text-orange-400" v-else-if="payment.type === 'partial'">Մասնակի</span>
                                <span class="text-blue-600" v-else-if="payment.type === 'full'">Ամբողջական</span>
                                <span class="text-red-600" v-else-if="payment.type === 'penalty'">Տուգանք</span>
                            </td>
                            <td @click="payment.status === 'initial' && !mini && selectPayment(index)">
                                <template v-if="payment.completed">
                                    <span class="text-lime-500" v-if="payment.cash"><i class="fa fa-money-bill pr-2"></i>Կանխիկ</span>
                                    <span class="text-blue-500" v-else><i class="fa fa-credit-card pr-2"></i>Անկանխիկ</span>
                                </template>
                            </td>
                            <td @click="payment.status === 'initial' && !mini && selectPayment(index)"
                                class="px-2 py-2">
                                <template v-if="payment.type !== 'penalty'">
                                    {{ payment.amountLabel }} <Dram/>
                                </template>
                                <template v-else>
                                    -
                                </template>
                            </td>
                            <td @click="payment.status === 'initial' && !mini && selectPayment(index)"
                                class="px-2 py-2">
                                {{makeMoney(payment.paid)}} <Dram/>
                            </td>
                            <td @click="payment.status === 'initial' && !mini && selectPayment(index)"
                                class="px-2 py-2">
                                {{ payment.mother }} <template v-if="payment.mother && payment.mother !== '-'">
                                <Dram/>
                            </template>
                            </td>
                            <td @click="payment.status === 'initial' && !mini && selectPayment(index)"
                                class="px-2 py-2 font-semibold">
                                {{ payment.finalLabel }} <Dram/>
                            </td>
                            <td v-if="false" class="px-2 py-2 text-center">
                                <template v-if="payment.status === 'initial'">
                                    <template v-if="mini">
                                        <span class="text-center">-</span>
                                    </template>
                                    <template v-else>
                                        <button @click="makeSinglePayment(payment)"
                                                :disabled="loadingPayment || !(contract.status === 'initial')"
                                                class="transition-all font-semibold relative bg-blue-600 hover:bg-blue-700 text-white rounded-lg px-3 pl-6 py-1 whitespace-nowrap">
                                            <div class="flex flex-nowrap text-xs">
                                            <span class="payment-spinner-handler">
                                                <svg class="animate-spin mr-1 h-3 w-3 text-white" fill="none" viewBox="0 0 24 24"
                                                     v-if="loadingPayment === payment.id">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                    <path class="opacity-75" fill="currentColor"
                                                          d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                </svg>
                                                <i v-else class="fa fa-money-bill"></i>
                                            </span>
                                                Վճարել
                                            </div>
                                        </button>
                                    </template>

                                </template>
                                <i v-else class="fa text-lime-500 fa-check"></i>
                            </td>
                            <td @click="payment.status === 'initial' && !mini && selectPayment(index)" class="px-2 py-2 text-center">
                                <template v-if="payment.status === 'initial'">
                                    <template v-if="mini">
                                        <span class="text-center">-</span>
                                    </template>
                                </template>
                                <i v-else class="fa text-lime-500 fa-check"></i>
                            </td>
                        </tr>
                    </template>

                    </tbody>
                </table>
                <div class="mt-6" v-if="selectedPayments.length">
                    <div
                        class="w-full p-5 pt-10 bg-white border border-gray-200 rounded-lg relative shadow dark:bg-gray-800 dark:border-gray-700">
                        <div class="flex items-center justify-between mb-4">
                            <h6 class="font-bold leading-none text-gray-900 dark:text-white">Ընտրված վճարումներ:</h6>
                        </div>
                        <div class="absolute top-2 right-2"><fwb-button @click="unselectPayments" size="xs" color="alternative"><i class="fa fa-times"></i></fwb-button></div>
                        <div class="flow-root">
                            <ul role="list" class="divide-y divide-gray-200 dark:divide-gray-700">
                                <li v-for="(payment, index) in selectedPayments" class="py-2">
                                    <div class="flex items-center space-x-4">
                                        <div class="flex-shrink-0">
                                            {{ index + 1 }}
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-medium text-gray-900 truncate dark:text-white">
                                                {{ payment.date }}
                                            </p>
                                        </div>
                                        <div
                                            class="inline-flex pr-2 items-center text-base font-semibold text-gray-900 dark:text-white">
                                            {{ payment.finalLabel }}
                                        </div>
                                    </div>
                                </li>
                                <li class="py-2">
                                    <div class="flex justify-end">
                                        <div class="flex gap-2">
                                            <span class="font-semibold text-blue-500">{{ makeMoney(selectedConf.sum, true) }} <Dram/></span>
                                            <template v-if="penaltyLeft">
                                                <span>+</span>
                                                <span>
                                    <span class="font-semibold text-rose-600">{{ makeMoney(penaltyLeft, true) }} <Dram/> </span><span class="text-xs">(տուգանք)</span>
                                </span>
                                                <span>=</span>
                                                <span class="font-semibold text-blue-500">{{ makeMoney(selectedConf.final, true) }} <Dram/></span>
                                            </template>
                                        </div>
                                    </div>
                                </li>
                                <li class="py-2">
                                    <div class="flex justify-end">
                                        <div class="inline-flex pr-2 items-center text-base  text-gray-900 dark:text-white">
                                            <div>

                                                <button @click="makeMultiplePayments"
                                                        class="relative transition-all text-sm font-semibold bg-blue-600 hover:bg-blue-700
                              text-white rounded-lg px-3 py-1 pl-6 whitespace-nowrap">
                        <span class="payment-spinner-handler">
                           <svg class="animate-spin mr-1 h-3 w-3 text-white" fill="none"
                                viewBox="0 0 24 24"
                                v-if="loadingPayment === 'multi'">
                                <circle class="opacity-25" cx="12" cy="12" r="10"
                                        stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor"
                                      d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                              </svg>
                          <i v-else class="fa fa-money-bill"></i>
                        </span>
                                                    Վճարել
                                                </button>
                                            </div>

                                        </div>
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div v-if="!mini && contract.status === 'initial'" class="mt-3 w-[500px]">
                    <fwb-tabs v-model="activeTab" variant="underline" class="py-4">
                        <fwb-tab name="partial" title="Մասնակի մարում" >
                            <div class="flex gap-4 justify-between">
                                <input min="0" :max="contract.left" :disabled="!contract.left"
                                       v-model="partialPaymentAmount" type="number" step="10" id="small-input"
                                       class="block p-2 text-gray-900 w-full border border-gray-300 rounded-lg bg-gray-50 sm:text-sm focus:ring-blue-500 focus:border-blue-500
                                   dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                                <fwb-button :disabled="!partialPaymentAmount" @click="makePartialPayment" color="green">Վճարել</fwb-button>
                            </div>
                        </fwb-tab>
                        <fwb-tab name="full" title="Ամբողջական մարում">
                            <table class="text-xs text-left w-full text-gray-500 dark:text-gray-400 payments-table">
                                <thead class="text-xs text-gray-700 uppercase bg-gray-100 dark:bg-gray-700 dark:text-gray-400">
                                <tr>
                                    <th scope="col" class="px-2 py-1">Տոկոս</th>
                                    <th scope="col" class="px-2 py-1">Տուգանք</th>
                                    <th scope="col" class="px-2 py-1">Մայր գումար</th>
                                    <th scope="col" class="px-2 py-1">Վերջնական</th>
                                    <th scope="col" class="px-2 py-1">Վճարել</th>
                                </tr>
                                </thead>
                                <tbody>
                                <tr class="border-b  dark:border-gray-700 cursor-pointer">
                                    <td class="px-2 py-2 dark:text-white">{{makeMoney(fullPaymentConfig.amount,true)}} <Dram/></td>
                                    <td class="px-2 py-2 dark:text-white">{{makeMoney(fullPaymentConfig.penalty,true)}} <Dram/></td>
                                    <td class="px-2 py-2 dark:text-white">{{makeMoney(fullPaymentConfig.mother,true)}} <Dram/></td>
                                    <td class="px-2 py-2 dark:text-white">{{makeMoney(fullPaymentConfig.final,true)}} <Dram/></td>
                                    <td class="px-2 py-2 dark:text-white"> <fwb-button size="xs" @click="makeFullPayment" color="green">Վճարել</fwb-button></td>
                                </tr>
                                </tbody>
                            </table>
                        </fwb-tab>
                        <fwb-tab name="execution" title="Իրացում">
                            <div class="flex gap-4 justify-between">
                                <input min="0" :disabled="!contract.left"
                                       v-model="executionPaymentAmount" type="number" step="10" id="small-input"
                                       class="block p-2 text-gray-900 w-full border border-gray-300 rounded-lg bg-gray-50 sm:text-sm focus:ring-blue-500 focus:border-blue-500
                                   dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                                <fwb-button :disabled="!executionPaymentAmount" @click="openExecutionModal" color="green">Իրացում</fwb-button>

                            </div>
                        </fwb-tab>
                    </fwb-tabs>
                </div>
            </div>

        </div>
    </div>
</template>

<script>
import getAxios from "@/axios";
import { computed,  onUpdated, ref, watch } from "vue";
import { alertError, alertSuccess, makeMoney } from "../../calc";
import { initFlowbite } from 'flowbite';
import { FwbTab, FwbTabs, FwbModal, FwbButton, FwbSpinner, FwbInput } from 'flowbite-vue'
import ConfirmModal from "@/components/modals/ConfirmModal.vue";
import Dram from "@/components/icons/Dram.vue";
import PaymentModal from "@/components/modals/PaymentModal.vue";
export default {
    name: "Payment", components: { PaymentModal, Dram, FwbInput, FwbTab,  FwbTabs, FwbModal, FwbButton, FwbSpinner, ConfirmModal},
    props: {
        mini: {
            type: Boolean, default: false
        },
        modelValue: Object,
    }, emits: [ 'update:modelValue' ], setup(props, ctx) {
        let resolveFunction = ref(() => {
        });
        const showConfirmModal = ref(false)
        const showPaymentModal = ref(false)
        const showDiscountModal = ref(false)
        const showExecutionModal = ref(false)
        const modalType = ref('regular');
        const pendingDiscountPayment = ref();
        const pendingAmount = ref(0)
        const pendingPayments = ref([])
        const pendingPayer = ref({})
        const pendingCash = ref(true)
        const modalAmount = ref(0);
        const discountAmount = ref('');
        const loadingPayment = ref();
        const partialPaymentAmount =ref();
        const executionPaymentAmount = ref();
        const canHaveAnotherAmount = ref(false)
        const penaltySectionRef = ref();
        const selectedPayments = computed(() => {
            return payments.value?.filter((v) => !!v.selected) || []
        })
        watch(() => props.modelValue, (value) => {
            initialPayments.value = value.payments
        })

        const selectedConf = computed(() => {
            let sum = 0;
            selectedPayments.value?.forEach((v) => {
                sum += v.final
            })
            return {
                sum,
                final:sum + penaltyLeft.value
            }
        })
        const selectPayment = (index) => {
            if(contract.value.status === 'initial'){
                initialPayments.value.forEach((v,i) => {
                    if(i<=index && v.status === 'initial'){
                        v.selected = true
                    }else{
                        v.selected = false
                    }
                })
            }

        }
        const unselectPayments = () => {
            initialPayments.value.forEach((v,i) => {
                v.selected = false
            })

        }
        const contract = computed(() => {
            return {
                ...props.modelValue,
                completed: props.modelValue?.status === 'completed'
            }
        })
        const initialPayments = computed(() => props.modelValue?.payments?.filter(v => (v.amount || v.mother)))
        const hasAnotherPayer = computed(() => {
            return payments.value.some(v => v.anotherPayer)
        })
        const calcPenalty = (payment, index) => {
            const date = onlyDate(payment.date)
            const today = onlyDate()
            const validPayments = initialPayments.value
            const paymentsCount = validPayments.length
            if (date < today && payment.status === 'initial') {
                let dateToCheck;
                if (index < paymentsCount - 1) {
                    const nextPayment = validPayments[index + 1]
                    dateToCheck = new Date(Math.min(today.getTime(), onlyDate(nextPayment.date).getTime()))
                } else {
                    dateToCheck = today
                }
                let difference = dateToCheck.getTime() - date.getTime()
                difference = difference / (1000 * 3600 * 24);
                return props.modelValue.left * difference * props.modelValue.penalty * 0.01
            } else return payment.penalty || 0
        }
        const payments = computed(() => {
            return initialPayments.value.map((v, i) => {
                let penalty = calcPenalty(v, i)
                let amount = v.amount
                const final = amount + (v.mother || 0)
                return {
                    id: v.id,
                    date: v.date,
                    from_date: v.from_date,
                    status: v.status,
                    paid: v.paid,
                    penalty: v.penalty || penalty,
                    penaltyLabel: v.penalty ? makeMoney(v.penalty, true) : makeMoney(penalty, true),
                    hasPenalty: !!penalty,
                    amount: amount,
                    amountLabel: makeMoney(amount, true),
                    mother: v.mother ? makeMoney(v.mother, true) : '-',
                    last_payment:v.last_payment,
                    passed: datePassed(v.date),
                    completed: v.status === 'completed',
                    initial: v.status === 'initial',
                    final: final,
                    type: v.type,
                    finalLabel: makeMoney(final, true),
                    selected: v.selected,
                    discount:v.discount,
                    anotherPayer:v.another_payer,
                    name: v.name,
                    surname: v.surname,
                    passport: v.passport,
                    phone: v.phone,
                    cash:v.cash,
                }
            })
        })
        const penaltySum = computed(() => {
            let sum = 0;
            payments.value.forEach(v => {
                if(v.status === 'initial'){
                    sum += v.penalty;
                }
            })
            return Math.round(sum /10) * 10;
        })
        const debtPayments = computed(() => payments.value?.filter(v => (v.passed && v.status === 'initial')))
        const debtSummary = computed(() => {
            let sum = 0;
            debtPayments.value.forEach(v => sum += v.final)
            return sum
        })
        onUpdated(() => {
            initFlowbite();
        })
        const submitConfirmationModal = (data) =>{
            if(data.anotherAmount){
                pendingAmount.value = data.amount;
            }else{
                pendingAmount.value = false;
            }
            if(data.anotherPayer){
                pendingPayer.value = data.payer
            }else{
                pendingPayer.value = false
            }
            pendingCash.value = data.cash
            resolveFunction.value()
        }
        const discountsSum = computed(() => {
            let sum = 0;
            contract.value.discounts?.forEach((v) => {
                if(v.status === 'accepted'){
                    sum += v.amount
                }
            })
            payments.value.filter(v => {return v.type === 'penalty' && v.status === 'completed'}).forEach((v) => {
                sum += v.amount
            })
            return Math.floor(sum /10) * 10;
        })
        const penaltyLeft = computed(() => {
            return fullPenalty.value - discountsSum.value
        })
        const fullPenalty = computed(() => {
            let penalty = penaltySum.value
            for(let i = 0;i < payments.value?.length; i++){
                let p = payments.value[i];
                if(p.status === 'completed' && p.penalty && p.type === 'regular'){
                    penalty += p.penalty
                }
            }
            return Math.round(penalty /10) * 10
        })
        const makeSinglePayment = (payment) => {
            makePayment([payment])
        }
        const makeMultiplePayments = () => {
            makePayment(selectedPayments.value)

        }
        const paymentResolve = (payments) => {
            showConfirmModal.value = false
            if (payments.length === 1) {
                loadingPayment.value = payments[0].id;
            } else {
                loadingPayment.value = 'multi'
            }
            getAxios().post('/api/make-regular-payment', {
                payments: (pendingAmount.value && payments.length < debtPayments.value.length)? debtPayments.value : payments,
                contract_id: contract.value?.id,
                amount:pendingAmount.value,
                payer:pendingPayer.value,
                cash:pendingCash.value,
                penalty:penaltyLeft.value
            }).then((res) => {
                if (res.data.success === 'success') {
                    alertSuccess('Վճարումը հաջողությամ կատարվել է')
                    ctx.emit('update:modelValue', res.data.contract)
                    partialPaymentAmount.value = ''
                } else {
                    alertError('Ինչ որ բան այնպես չէ')
                }
                loadingPayment.value = null
            }).catch((e) => {
                loadingPayment.value = null
                alertError('Ինչ որ բան այնպես չէ')
            })
        }
        const makePayment = (payments) => {
            pendingPayments.value = payments
            canHaveAnotherAmount.value = true
            showConfirmModal.value = true;
            modalAmount.value = payments.length === 1 ? payments[0].final : selectedConf.value.sum;
            modalType.value = 'regular'
            resolveFunction.value = () => paymentResolve(payments)
        }
        const makePartialPayment = () => {
            if(partialPaymentAmount.value > 0 && partialPaymentAmount.value <= contract.value.left){
                showConfirmModal.value = true
                canHaveAnotherAmount.value = false
                modalAmount.value = partialPaymentAmount.value
                modalType.value = 'partial'
                resolveFunction.value = () => {
                    loadingPayment.value = 'partial'
                    showConfirmModal.value = false
                    if(debtPayments.value.length){
                        pendingAmount.value = partialPaymentAmount.value + debtSummary.value
                        paymentResolve(debtPayments.value)
                    }else{
                        getAxios().post('/api/make-partial-payment', {
                            amount: partialPaymentAmount.value, contract_id: contract.value?.id,
                            payer:pendingPayer.value,cash:pendingCash.value,penalty:penaltyLeft.value
                        }).then((res) => {
                            loadingPayment.value = null
                            alertSuccess('Վճարումը հաջողությամ կատարվել է')
                            partialPaymentAmount.value = ''
                            ctx.emit('update:modelValue', res.data.contract)
                        }).catch((e) => {
                            loadingPayment.value = null
                            alertError('Ինչ որ բան այնպես չէ')
                        })
                    }

                }

            }else{
                alert('Սխալ ներմուծված գումար')
            }
        }
        const makeFullPayment = () => {
            showConfirmModal.value = true
            modalAmount.value = fullPaymentConfig.value.final;
            modalType.value = 'full'
            canHaveAnotherAmount.value = false
            resolveFunction.value = () => {
                loadingPayment.value = 'full'
                showConfirmModal.value = false
                getAxios().post('/api/make-full-payment', {
                    amount: fullPaymentConfig.value?.final, contract_id: contract.value?.id,
                    payer:pendingPayer.value, hasPenalty:penaltyLeft.value,
                    cash:pendingCash.value
                }).then((res) => {
                    loadingPayment.value = null
                    alertSuccess('Վճարումը հաջողությամ կատարվել է')
                    ctx.emit('update:modelValue', res.data.contract)
                }).catch((e) => {
                    loadingPayment.value = null
                    alertError('Ինչ որ բան այնպես չէ')
                })
            }


        }

        const executeContract = () => {
            loadingPayment.value = 'execution'
            showExecutionModal.value = false
            getAxios().post('/api/execute-contract', {
                amount: executionPaymentAmount.value, contract_id: contract.value?.id,
            }).then((res) => {
                loadingPayment.value = null
                alertSuccess('Պայմանագիրը հաջողությամբ իրացված է')
                partialPaymentAmount.value = ''
                ctx.emit('update:modelValue', res.data.contract)
            }).catch((e) => {
                loadingPayment.value = null
                alertError('Ինչ որ բան այնպես չէ')
            })

        }
        const openExecutionModal = () => {
            showExecutionModal.value = true
        }
        const openDiscountModal = (payment) =>{
            if(!penaltyLeft.value){
                alert('Տուգանքն ամբողջությամբ մարված է')

            }else{
                showDiscountModal.value = true
                pendingDiscountPayment.value = payment
            }

        }

        const requestDiscount = () => {
            if(discountAmount.value && discountAmount.value <= penaltyLeft.value){
                loadingPayment.value = true
                showDiscountModal.value = false
                getAxios().post('/api/request-discount',{
                    contract_id:contract.value.id, discount:discountAmount.value,
                    amount:pendingAmount.value
                }).then((res) => {
                    discountAmount.value = ''
                    if (res.data.success === 'success') {
                        alertSuccess('Հարցումն ուղղարկված է')
                        ctx.emit('update:modelValue', res.data.contract)
                    } else {
                        alertError('Ինչ որ բան այնպես չէ')
                    }
                    loadingPayment.value = null
                }).catch((e) => {
                    discountAmount.value = ''
                    loadingPayment.value = null
                    alertError('Ինչ որ բան այնպես չէ')
                })
            }else{
                alert('Սխալ ներմուծված գումար')
            }
        }
        const datePassed = (date) => {
            return  onlyDate() >= onlyDate(date);
        }
        function createDateFromFormat(dateString) {
            const [day, month, year] = dateString.split('.');
            return new Date(year, month - 1, day);
        }

        const onlyDate = (date = null) => {
            date = date ? createDateFromFormat(date) : new Date()
            date.setHours(0, 0, 0, 0)
            return date
        };
        const fullPaymentConfig = computed(() => {
            let res = 0;
            payments.value.forEach((v,i) => {
                const date = onlyDate(v.date)
                let start_date =  false
                if(i === 0){
                    start_date = onlyDate(contract.value.date)
                }else if(v.from_date){
                    start_date = onlyDate(v.from_date)
                }
                const today = onlyDate()
                if(v.status === 'initial'){
                    if (date <= today) {
                        res += v.amount
                    }else if(start_date && today > start_date){
                        const oneDay = 24 * 60 * 60 * 1000;
                        const diffDays = Math.round(Math.abs((today - start_date) / oneDay));
                        console.log(diffDays, 'diffDays')
                        res += Math.round(diffDays * contract.value.rate * 0.01 * contract.value.left /10) * 10
                    }
                }
            })
            let amount = res;
            res += contract.value?.left
            res+= penaltyLeft.value
            return {
                penalty:penaltyLeft.value,
                amount:amount,
                mother:contract.value?.left,
                final: res
            }
        })

        const activeTab = ref('partial')
        return {
            makeMoney,
            makePartialPayment,
            makeFullPayment,
            partialPaymentAmount,
            executeContract,
            openExecutionModal,
            executionPaymentAmount,
            loadingPayment,
            selectPayment,
            makeMultiplePayments,
            datePassed,
            makePayment,
            selectedPayments,
            fullPenalty,
            payments,
            contract,
            activeTab,
            showConfirmModal,
            selectedConf,
            resolveFunction,
            modalType,
            modalAmount,
            penaltyLeft,
            showDiscountModal,
            showExecutionModal,
            pendingDiscountPayment,
            discountsSum,
            openDiscountModal,
            discountAmount,
            requestDiscount,
            submitConfirmationModal,
            canHaveAnotherAmount,
            makeSinglePayment,
            penaltySectionRef,
            fullPaymentConfig,
            debtPayments,
            showPaymentModal,
            unselectPayments,
            hasAnotherPayer
        }
    }
}
</script>


<style scoped>
.payment-spinner-handler {
    line-height: 1;
    position: absolute;
    left: 7px;
    top: 6px;
}

.payments-table td {
    white-space: nowrap;
}
</style>
