<template>
    <fwb-modal v-if="show" @close="closeModal">
        <template #header>
            <div class="flex items-center text-lg dark:text-gray-400 gap-4">
                <span>
                    Ուշադրություն Payment
                </span>

            </div>
        </template>
        <template #body>
            <div class="flex justify-between items-baseline">
                <div class="flex w-min gap-4">
                    <fwb-radio v-model="cashPayment" label="Կանխիկ" :value="true" />
                    <fwb-radio v-model="cashPayment" label="Անկանխիկ" :value="false" />
                </div>
                <div class="flex gap-4" >
                    <fwb-button v-if="!showAnotherAmount && anotherPayment" outline color="yellow" @click="() => showAnotherAmount = true" size="xs">Այլ գումար</fwb-button>
                    <fwb-button v-if="!showAnotherPayer" outline @click="() => showAnotherPayer = true" size="xs">Այլ Վճարող</fwb-button>
                </div>
            </div>
            <template v-if="type === 'regular'">
                <template v-if="penalty">
                    <p class="text-base leading-relaxed text-gray-500 dark:text-gray-400 mt-2">
                        Դուք ունեք <span class="text-rose-600 font-bold">{{makeMoney(penalty)}}</span> դրամի չվճարված տուգանք
                    </p>
                    <table class="text-xs text-left w-full text-gray-500 dark:text-gray-400 payments-table mt-4">
                        <thead class="text-xs text-gray-700 uppercase bg-gray-100 dark:bg-gray-800 dark:text-gray-400">
                        <tr>
                            <th scope="col" class="px-2 py-1">Տոկոս</th>
                            <th scope="col" class="px-2 py-1">Տուգանք</th>
                            <th scope="col" class="px-2 py-1">Վերջնական</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr class="border-b dark:border-gray-900">
                            <td class="px-2 py-2 dark:text-white">{{makeMoney(amount)}} <Dram/></td>
                            <td class="px-2 py-2 dark:text-white">{{makeMoney(penalty)}} <Dram/></td>
                            <td class="px-2 py-2 dark:text-white">{{makeMoney(summary)}} <Dram/></td>
                        </tr>
                        </tbody>
                    </table>

                    <p class="text-base leading-relaxed text-gray-500 dark:text-gray-400 mt-2">
                        Դուք հաստատու՞մ եք <span class="text-blue-500 font-bold">{{makeMoney(summary)}}</span> դրամի մուծում
                    </p>
                </template>
                <template v-else>
                    <p class="text-base leading-relaxed text-gray-500 dark:text-gray-400 mt-2">
                        Դուք հաստատու՞մ եք <span class="text-blue-500 font-bold">{{makeMoney(amount)}}</span> դրամի մուծում
                    </p>
                </template>


            </template>
            <template v-else-if="type === 'penalty'">
                <p class="text-base leading-relaxed text-gray-500 dark:text-gray-400 mt-2">
                    Դուք հաստատու՞մ եք <span class="text-blue-500 font-bold">{{makeMoney(penalty)}}</span> դրամի տուգանքի մուծում
                </p>
            </template>

            <template v-else-if="type === 'partial'">
                <template v-if="penalty">
                    <p class="text-base leading-relaxed text-gray-500 dark:text-gray-400 mt-2">
                        Դուք ունեք <span class="text-rose-600 font-bold">{{makeMoney(penalty)}}</span> դրամի չվճարված տուգանք
                    </p>
                    <table class="text-xs text-left w-full text-gray-900 dark:text-gray-400 payments-table mt-4">
                        <thead class="text-xs text-gray-700 uppercase bg-gray-100 dark:bg-gray-800 dark:text-gray-400">
                        <tr>
                            <th scope="col" class="px-2 py-1">Մասնակի</th>
                            <th scope="col" class="px-2 py-1">Տուգանք</th>
                            <th scope="col" class="px-2 py-1">Վերջնական</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr class="border-b dark:border-gray-900 ">
                            <td class="px-2 py-2 dark:text-white">{{makeMoney(amount)}} <Dram/></td>
                            <td class="px-2 py-2 dark:text-white">{{makeMoney(penalty)}} <Dram/></td>
                            <td class="px-2 py-2 dark:text-white">{{makeMoney(summary)}} <Dram/></td>
                        </tr>
                        </tbody>
                    </table>
                    <p class="text-base leading-relaxed text-gray-500 dark:text-gray-400 mt-2">
                        Դուք հաստատու՞մ եք <span class="text-blue-500 font-bold">{{makeMoney(summary)}}</span> դրամի մուծում
                    </p>
                </template>
                <template v-else>
                    <p class="text-base leading-relaxed text-gray-500 dark:text-gray-400 mt-2">
                        Դուք հաստատու՞մ եք <span class="text-blue-500 font-bold">{{makeMoney(amount)}}</span> դրամի մուծում
                    </p>
                </template>
            </template>
            <template v-else-if="type === 'full'">
                <p class="text-base leading-relaxed text-gray-500 dark:text-gray-400 mt-2">
                    Դուք հաստատու՞մ եք <span class="text-blue-500 font-bold">{{makeMoney(amount)}}</span> դրամի ամբողջական մուծում
                </p>
            </template>






            <template v-if="anotherPayment">
                <div v-if="showAnotherAmount" class="border bg-white dark:bg-gray-700 rounded-lg shadow p-4 w-min mt-4">
                    <div  class="">
                        <div class="w-min">
                            <div class="flex justify-between">
                                <label for="amount" class="dark:text-gray-300 text-sm">Նշեք գումարը</label>
                                <button @click="closeAnotherAmount" class="ml-5 dark:text-gray-300"><i class="fa fa-times"></i></button>
                            </div>

                            <fwb-input size="sm" v-model="anotherAmount" autocomplete="off" id="amount" type="number" class="w-min mt-1"></fwb-input>
                        </div>
                    </div>
                </div>

            </template>
            <div v-if="showAnotherPayer" class="border bg-white dark:bg-gray-700 rounded-lg shadow p-4 mt-4">
                <div >
                    <div class="">
                        <div class="flex justify-between">
                            <label for="amount" class="dark:text-gray-300 text-sm">Նշեք վճարողի տվյալները</label>
                            <button @click="() => showAnotherPayer = false" class="ml-5 dark:text-gray-300"><i class="fa fa-times"></i></button>
                        </div>
                        <div class="w-max">
                            <div class="flex justify-between gap-4 items-center">
                                <label for="name" class="text-sm dark:text-gray-300">Անուն</label>
                                <fwb-input size="sm" v-model="anotherPayerForm.name" autocomplete="off" id="name" class="w-min mt-1"></fwb-input>
                            </div>
                            <div class="flex justify-between gap-4 items-center">
                                <label for="surname" class="text-sm dark:text-gray-300">Ազգանուն</label>
                                <fwb-input size="sm" v-model="anotherPayerForm.surname" autocomplete="off" id="surname" class="w-min mt-1"></fwb-input>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mt-6 py-4 px-2  border border-red-600 rounded-lg">
                <p class="text-xs font-bold text-gray-600 dark:text-gray-300"> {{alertMessage}} ! </p>
            </div>



        </template>
        <template #footer>
            <div class="flex justify-between">
                <fwb-button @click="closeModal" color="alternative">
                    Չեղարկել
                </fwb-button>
                <fwb-button @click="submit" color="green">
                    Այո
                </fwb-button>
            </div>
        </template>
    </fwb-modal>
</template>

<script>
import { FwbModal, FwbButton, FwbInput, FwbRadio } from "flowbite-vue";
import { computed, reactive, ref } from "vue";
import { makeMoney } from "../../calc";
import Dram from "@/components/icons/Dram.vue";

export default {
    name: 'PaymentModal', methods: { makeMoney },
    props: {
        penalty: Number, show: Boolean, type: {
            type: String, default: 'regular'
        }, amount: Number, anotherPayment: {
            type: Boolean, default: false
        }, debt: Array
    },
    emits: [ 'close', 'submit', 'order' ],
    components: { Dram, FwbRadio, FwbInput, FwbModal, FwbButton },
    setup(props, ctx) {
        const showAnotherAmount = ref(false)
        const showAnotherPayer = ref(false)
        const anotherAmount = ref('')
        const cashPayment = ref(true)
        const anotherPayerForm = ref({
            name: '', surname: '',
        })
        const summary = computed(() => {
            return props.amount + props.penalty
        })
        const emptyPayerForm = () => {
            anotherPayerForm.value = {
                name: '', surname: '', passport: '', phone: '',
            }
        }
        const resetModal = () => {
            showAnotherAmount.value = false
            showAnotherPayer.value = false
            anotherAmount.value = ''
            cashPayment.value = true
            emptyPayerForm()
        }
        const closeModal = () => {
            ctx.emit('close');
            resetModal()
        }
        const submit = () => {
            ctx.emit('submit', {
                anotherAmount: showAnotherAmount.value,
                amount: anotherAmount.value,
                anotherPayer: showAnotherPayer.value,
                payer: anotherPayerForm.value,
                cash: cashPayment.value
            });
            resetModal()
        }
        const alertConfig = computed(() => {
            let amount = null;
            let penalty = null;
            let partial = null;
            let full = false;
            const returnRes = () => {
                return {
                    penalty, amount, partial, full
                }
            }
            if (props.type === 'regular') {
                if (showAnotherAmount.value && anotherAmount.value) {
                    if (props.penalty) {
                        if (anotherAmount.value <= props.penalty) {
                            penalty = anotherAmount.value
                            return returnRes();
                        } else {
                            if (anotherAmount.value <= summary.value) {
                                penalty = props.penalty
                                amount = anotherAmount.value - props.penalty
                                return returnRes()
                            } else {
                                penalty = props.penalty
                                let difference = anotherAmount.value - summary.value
                                amount = props.amount + difference % 1000
                                partial = difference - difference % 1000
                                return returnRes()
                            }
                        }
                    } else {
                        if (anotherAmount.value <= props.amount) {
                            amount = anotherAmount.value
                            return returnRes()
                        } else {
                            let difference = anotherAmount.value - props.amount
                            amount = props.amount + difference % 1000
                            partial = difference - difference % 1000
                            return returnRes()
                        }
                    }
                } else {
                    penalty = props.penalty
                    amount = props.amount
                    return returnRes()
                }
            } else if (props.type === 'partial') {
                amount = props.amount
                penalty = props.penalty
                return returnRes()
            } else if (props.type === 'penalty') {
                if (showAnotherAmount.value && anotherAmount.value) {
                    if (anotherAmount.value <= props.penalty) {
                        penalty = anotherAmount.value
                        return returnRes()
                    } else {
                        penalty = props.penalty
                        let difference = anotherAmount.value - props.penalty
                        amount = difference % 1000
                        partial = difference - difference % 1000
                        return returnRes()
                    }
                } else {
                    penalty = props.penalty
                    return returnRes()
                }
            } else if (props.type === 'full') {
                full = true;
                amount = props.amount
                return returnRes()
            }
            return {
                penalty, amount, partial, full
            }
        })
        const alertMessage = computed(() => {
            let message = 'Սեղմելով Այո կոճակը դուք հաստատում եք ';
            let config = alertConfig.value;
            if (config.full) {
                message += makeMoney(config.amount, true) + 'դրամի ամբողջական վճարում'
            } else {
                if (config.penalty) {
                    message += makeMoney(config.penalty, true) + 'դրամ տուգանքի'
                }
                if (config.amount) {
                    message += (config.penalty ? ', ' : ' ') + makeMoney(config.amount, true) + 'դրամ տոկոսի'
                }
                if (config.partial) {
                    message += (config.penalty || config.amount ? ', ' : ' ') + makeMoney(config.partial, true) + 'դրամ մասնակի'
                }
                message += ' մուծում'
            }
            return message;
        })

        const closeAnotherAmount = () => {
            showAnotherAmount.value = false
            anotherAmount.value = ''
        }
        return {
            submit,
            closeModal,
            showAnotherAmount,
            anotherAmount,
            cashPayment,
            showAnotherPayer,
            anotherPayerForm,
            closeAnotherAmount,
            summary,
            alertMessage
        }
    }
}
</script>


<style scoped>

</style>
