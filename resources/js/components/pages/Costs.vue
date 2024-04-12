<template>
    <div class="overflow-x-auto sm:rounded-lg container mx-auto pt-10 p-4">
        <span v-if="pageLoading" class="pl-2 page-spinner-handler"><fwb-spinner size="6" color="yellow" /></span>
        <div class="flex items-center gap-4">
            <h2 class="text-2xl dark:text-white">Ծախսեր</h2>
            <div class="flex w-min gap-4">
                <fwb-radio v-model="type" label="Ելք" value="out" />
                <fwb-radio v-model="type" label="Մուտք" value="in" />
            </div>
        </div>

        <div class="relative overflow-hidden bg-white shadow-md dark:bg-gray-800 sm:rounded-lg mt-4 p-5">
            <form @submit="submitForm">
                <div class="grid grid-cols-12 gap-4 mt-2">
                    <div class="col-span-4">
                        <div>
                            <span class="text-gray-500 dark:text-gray-300">Վճարման տեսակը</span>
                            <div class="mt-2">
                                <div class="flex w-min gap-4">
                                    <fwb-radio v-model="cashPayment" label="Կանխիկ" :value="true" />
                                    <fwb-radio v-model="cashPayment" label="Անկանխիկ" :value="false" />
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-span-8">
                        <template v-if="type === 'out'">
                            <span class="text-gray-500 dark:text-gray-300">Ստացող</span>
                            <div class="mt-2">
                                <fwb-input required id="receiver_input" class="max-w-md" v-model="receiver"/>
                            </div>
                        </template>
                        <template v-else>
                            <span class="text-gray-500 dark:text-gray-300">Աղբյուր</span>
                            <div class="mt-2">
                                <fwb-input required id="source_input" class="max-w-md" v-model="source"/>
                            </div>
                        </template>

                    </div>
                </div>

                <div class="grid grid-cols-12 gap-4 mt-4">
                    <div class="col-span-4">
                        <span class="text-gray-500 dark:text-gray-300">Նպատակ</span>
                        <div class="mt-2">
                            <fwb-select :options="purposes" v-model="purpose"/>
                            <div v-if="purpose === 'other'" class="mt-4">
                                <p class="text-gray-500 dark:text-gray-300 mb-2">Նշել նպատակը</p>
                                <fwb-input id="other_purpse_input" v-model="otherPurpose" required/>
                            </div>
                        </div>

                    </div>
                    <div class="col-span-8">
                        <span class="text-gray-500 dark:text-gray-300">Գումար</span>
                        <div class="mt-2">
                            <fwb-input id="amount_input" required class="max-w-md" type="number" v-model="amount"/>
                            <p v-if="purpose === 'bank_cashbox_charging'" class="text-orange-400 text-xs mt-2">
                                Ուշադրություն! Անկանխիկ հաշվի համալրման դեպքում գումարը հանվում է կանխիկ դրամարկղից և գումարվում անկանխիկ դրամարկղին
                            </p>
                        </div>

                    </div>
                </div>
                <div class="mt-4">
                    <fwb-button type="submit">Հաստատել</fwb-button>
                </div>
            </form>

        </div>
    </div>
</template>

<script>
import getAxios from "../../axios";
import { computed, nextTick, onMounted, ref, watch } from "vue";
import { FwbButton, FwbInput, FwbRadio, FwbSelect, FwbSpinner } from 'flowbite-vue'
import { alertError, alertSuccess } from "@/calc";

export default {
    name: "TodaysList",
    components: { FwbRadio, FwbButton, FwbInput, FwbSelect, FwbSpinner},
    setup() {
        const pageLoading = ref(false);
        const cashPayment = ref(true)
        const type = ref('out')
        const purposes = computed(() => {
            if(type.value === 'out'){
                return [
                    { value: 'bank_cashbox_charging', name: 'Անկախիկ հաշվի համալրում'},
                    { value: 'rent', name: 'Տարածքի վարձակալություն'},
                    { value: 'commission', name: 'Միջնորդավճար'},
                    { value: 'phone_charging', name: 'Հեռախոսի լիցքավորում'},
                    { value: 'gasoline_fee', name: 'Բենզինի վճար'},
                    { value: 'other', name: 'Այլ'},
                ]
            }
            return [
                { value: 'unknown_transfer', name: 'Անհայտ փոխանցում'},
                { value: 'other', name: 'Այլ'},
            ]
        })
        const purpose = ref('bank_cashbox_charging')
        const amount = ref('')
        const receiver = ref('')
        const source = ref('')
        watch(() => purpose.value,(value) => {
            if((type.value === 'in' && source.value) || type.value === 'out' && receiver.value){
                if(value === 'other'){
                    nextTick(() => {
                        document.querySelector('input#other_purpse_input').focus()
                    })

                }else{
                    otherPurpose.value = ''
                    nextTick(() => {
                        document.querySelector('input#amount_input').focus()
                    })

                }
            }else{
                if(type.value === 'out'){
                    nextTick(() => {
                        document.querySelector('input#receiver_input').focus()
                    })

                }else{
                    nextTick(() => {
                        document.querySelector('input#source_input').focus()
                    })

                }
            }

        })
        watch(() => type.value, (value) => {
            nextTick(() => {
                purpose.value = purposes.value[0].value
                if(value === 'out'){
                    nextTick(() => {
                        document.querySelector('input#receiver_input').focus()
                    })
                }else{
                    nextTick(() => {
                        document.querySelector('input#source_input').focus()
                    })

                }
            })
        },{
            immediate:true
        })
        const otherPurpose = ref('')
        const purposeTranslation = computed(() => {
            return purposes.value.find(v => v.value === purpose.value)?.name
        })
        const submitForm = (e) => {
            e.preventDefault()
            pageLoading.value = true
            getAxios().post('/api/add-cost',{
                amount:amount.value,
                purpose:purpose.value,
                otherPurpose:otherPurpose.value,
                purposeTranslation:purposeTranslation.value,
                cash:cashPayment.value,
                receiver:receiver.value,
                source:source.value,
                type:type.value
            }).then((res) => {
                pageLoading.value = false
                if(res.data.success === 'success'){
                    alertSuccess('Ծախսը հաջողությամբ ավելացված է')
                    amount.value = ''
                    otherPurpose.value = ''
                    receiver.value = ''
                    source.value = ''
                }
            }).catch((e) => {
                pageLoading.value = false
                alertError('Ինչ որ բան այնպես չէ')
            })
        }

        return {
            pageLoading,
            purposes,
            purpose,
            amount,
            otherPurpose,
            submitForm,
            cashPayment,
            type,
            receiver,
            source
        }
    }
}
</script>


<style scoped>
</style>
