<template>
    <div class="container mx-auto pt-10 p-4 pb-6">
        <div class="w-full ">
            <div class="flex items-center mb-4">
                <h2 class="text-2xl text-orange-400 font-bold">Պայմանագրի փոփոխման էջ</h2>
                <span v-if="!pageLoaded" class="pl-2 page-spinner-handler"><fwb-spinner size="6" color="yellow" /></span>
            </div>
            <template v-if="pageLoaded">
                <template v-if="contract">
                    <section class="bg-white dark:bg-gray-800 rounded-lg shadow-inner shadow-orange-400 border border-gray-200 dark:border-gray-700">
                        <div class="p-8">
                            <div class="flex w-max gap-4 items-baseline relative">
                                <h2 class="mb-4 text-gray-900 dark:text-white">Փնտրել հաճախորդ</h2>
                                <div class="flex">
                                    <input type="text" @focus="focusSearchInput" @blur="blurSearchInput" v-model="searchValue"
                                           @input="search" class="bg-gray-50 border border-gray-300 text-gray-900 text-xs rounded-l-lg
                               focus:ring-blue-500 focus:border-blue-500 block
                               p-2.5 dark:bg-gray-700 dark:border-gray-600
                               dark:placeholder-gray-400 dark:text-white
                               dark:focus:ring-blue-500 dark:focus:border-blue-500">
                                    <button class="bg-blue-600 text-white rounded-r-lg flex align-center items-center px-4 shadow"><i class="fa fa-search "></i></button>
                                </div>

                                <div class="absolute top-full z-10 left-0 right-0 bg-gray-100 dark:bg-gray-900 rounded-lg shadow border border-gray-200 dark:border-gray-700" v-if="searchActive && searchValue">
                                    <dl class="text-gray-900 divide-y divide-gray-200 dark:text-white dark:divide-gray-700 p-4">
                                        <template v-if="searchResults.length">
                                            <div v-for="value in searchResults" @click="clientSelected(value)">
                                                <div class="flex flex-col pb-1 cursor-pointer hover:bg-gray-200 dark:hover:bg-gray-700 rounded-lg p-1">
                                                    <dt class="text-gray-500 text-xs dark:text-gray-400">{{value.name + '  ' + value.surname}}</dt>
                                                    <dd class="text-sm font-medium">{{value.email}}</dd>
                                                </div>
                                            </div>
                                        </template>
                                        <template v-else>
                                            <span class="text-gray-500 text-xs dark:text-gray-400">Արդյունքներ չկան</span>
                                        </template>

                                    </dl>
                                </div>
                            </div>

                            <form @submit="submitForm" >
                                <div class="grid md:grid-cols-2 gap-4 mt-4">
                                    <template v-for="(section,index) in sections">
                                        <div class="rounded-lg relative shadow p-4 pt-6 border shadow-orange-400 border-gray-200 dark:border-gray-700">
                                            <div class="grid grid-cols-2 gap-4">
                                                <template v-for="field in section.fields">
                                                    <div :class="{'col-span-2': field.cols === 2}">
                                                        <label :for="'field_' + field.name"
                                                               class="block mb-2 text-xs font-medium text-gray-900 dark:text-white"><i :class="'pr-2 fa fa-' + field.icon"></i>{{ field.label }}</label>
                                                        <template v-if="field.input === 'input'">
                                                            <input autocomplete="off" :type="field.type"
                                                                   :step="numberSteps[field.name]" :name="field.name"
                                                                   :id="'field_' + field.name"
                                                                   :disabled="field.disabled"
                                                                   v-model="form[field.name]"
                                                                   class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg
                               focus:ring-blue-500 focus:border-blue-500 block
                               w-full p-2.5 dark:bg-gray-700 dark:border-gray-600
                               dark:placeholder-gray-400 dark:text-white
                               dark:focus:ring-blue-500 dark:focus:border-blue-500"
                                                                   placeholder=" " :required="field.required"/>
                                                        </template>
                                                        <template v-else-if="field.input === 'textarea'">
                        <textarea v-model="form[field.name]" :rows="field.rows" autocomplete="off"
                                  :name="field.name" :id="'field_' + field.name"
                                  :disabled="field.disabled"
                                  class="block p-2.5 w-full text-sm text-gray-900 bg-gray-50 rounded-lg border border-gray-300 focus:ring-blue-500 focus:border-blue-500
                                  dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                                  :required="field.required"></textarea>
                                                        </template>
                                                        <template v-else-if="field.input === 'datetime'">
                                                            <datepicker :disabled="field.disabled" type="date" :id="'field_' + field.name"
                                                                        v-model:value="form[field.name]"></datepicker>
                                                        </template>

                                                        <template v-else-if="field.input === 'select'">
                                                            <select :id="'field_' + field.name"
                                                                    :disabled="field.disabled"
                                                                    :name="field.name"
                                                                    v-model="form[field.name]"
                                                                    class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5
                                dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                                                                <option v-for="option in field.items" :value="option.id">{{option[field.fieldName]}}</option>
                                                            </select>
                                                        </template>


                                                    </div>
                                                </template>
                                                <template v-if="index === 1">
                                                    <div class="col-span-2 border-t-2 pt-2 border-orange-500">
                                                        <div class="block mb-2 text-xs font-medium text-gray-900 dark:text-white">
                                                            <i class="pr-2 fa fa-list"></i>
                                                            Իրեր(<span class="text-orange-700 dark:text-orange-400 px-1">{{form.items.filter(v => !v.deleted).length}}</span>)
                                                            <div class="flex flex-col gap-3 mt-2">
                                                                <template v-for="(item,index) in form.items">
                                                                    <div class="rounded-md relative shadow p-4 pt-2 border border-orange-500" :class="{'hidden':item.deleted}">
                                                                        <p>{{index+1}}.</p>
                                                                        <div class="absolute top-1 right-1" v-if="form.items.filter(v => !v.deleted).length > 1"><i class="fa fa-trash text-red-500 cursor-pointer" @click="removeItem(index)"></i></div>
                                                                        <div class="grid grid-cols-2 gap-3">
                                                                            <div>
                                                                                <label class="block mb-2 text-xs font-medium text-gray-900 dark:text-white" :for="'category_' + index"><i class="fa fa-list pr-2"></i>Տեսակը</label>
                                                                                <select :id="'category_' + index"
                                                                                        :name="'category_' + index"
                                                                                        @change="onItemChange(index)"
                                                                                        v-model="item.category_id"
                                                                                        class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                                                                                    <option v-for="option in categories" :value="option.id">{{option.title}}</option>
                                                                                </select>
                                                                            </div>
                                                                            <div>
                                                                                <label class="block mb-2 text-xs font-medium text-gray-900 dark:text-white" :for="'description_' + index"><i class="fa fa-comment pr-2"></i>Նկարագրություն</label>
                                                                                <textarea v-model="item.description" rows="2" autocomplete="off"
                                                                                          :id="'description_' + index"
                                                                                          :name="'description_' + index"
                                                                                          class="block p-2.5 w-full text-sm text-gray-900 bg-gray-50 rounded-lg border border-gray-300 focus:ring-blue-500 focus:border-blue-500
                                  dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"></textarea>
                                                                            </div>
                                                                            <template v-if="isGold(item.category_id)">
                                                                                <div>
                                                                                    <label class="block mb-2 text-xs font-medium text-gray-900 dark:text-white" :for="'type_' + index"><i class="fa fa-scale-balanced pr-2"></i>Հարգը</label>
                                                                                    <fwb-input autocomplete="off" :id="'type_' + index" :name="'type_' + index" v-model="item.type" type="text"></fwb-input>
                                                                                </div>
                                                                                <div>
                                                                                    <label class="block mb-2 text-xs font-medium text-gray-900 dark:text-white" :for="'weight_' + index"><i class="fa fa-scale-unbalanced-flip pr-2"></i>Քաշը</label>
                                                                                    <fwb-input :id="'weight_' + index" :name="'weight_' + index" v-model="item.weight" type="number"></fwb-input>
                                                                                </div>
                                                                                <div>
                                                                                    <label class="block mb-2 text-xs font-medium text-gray-900 dark:text-white" :for="'clear_weight_' + index"><i class="fa fa-scale-unbalanced pr-2"></i>Մաքուր քաշը</label>
                                                                                    <fwb-input :id="'clear_weight_' + index" :name="'clear_weight_' + index" v-model="item.clear_weight" type="number"></fwb-input>
                                                                                </div>
                                                                            </template>
                                                                        </div>
                                                                    </div>
                                                                </template>
                                                                <fwb-button size="sm" color="yellow" class="ml-2" type="button" @click="addItem">Ավելացնել իր <i class="fa fa-plus"></i></fwb-button></div>
                                                        </div>

                                                    </div>
                                                </template>

                                            </div>
                                        </div>

                                    </template>
                                </div>
                                <div class="flex justify-end">
                                    <fwb-button color="light" type="button" class="my-2 mr-4" size="xs" @click="() => $router.go(-1)">Չեղարկել</fwb-button>
                                    <button type="submit"
                                            :disabled="requestLoading"
                                            class=" mt-2 flex focus:outline-none text-white bg-green-700 hover:bg-green-800
                    focus:ring-4 focus:ring-green-300 font-medium
                    rounded-lg text-xs px-3 py-2 mr-2 mb-2 dark:bg-green-600
                    dark:hover:bg-green-700 dark:focus:ring-green-800">
                                        <svg class="animate-spin mr-1 h-3 w-3 text-white"
                                             fill="none" viewBox="0 0 24 24"
                                             v-if="requestLoading">
                                            <circle class="opacity-25" cx="12" cy="12" r="10"
                                                    stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor"
                                                  d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg><span>Պահպանել</span></button>
                                </div>


                            </form>


                        </div>
                    </section>
                </template>
                <template v-else>
                    <div class="bg-white dark:bg-gray-800 rounded-lg p-4 shadow border border-gray-200 dark:border-gray-700 mb-4">
                        <p class="text-gray-800 dark:text-gray-300">Պայմանագիրը չի գտնվել կամ ավարտվել է և փոփոխման ենթակա չէ</p>
                    </div>
                </template>

            </template>

        </div>
    </div>

</template>

<script>
import getAxios from "@/axios";
import {computed, defineComponent, reactive, ref, watch} from "vue";

const numberSteps = {
    worth: 100,
    given: 100,
    rate: 0.01,
    penalty: 0.01,
}
import { FwbButton, FwbInput, FwbSpinner } from 'flowbite-vue'
import { useRoute } from "vue-router";
import { alertSuccess } from "@/calc";
import router from "@/router";
export default defineComponent(
    {
        name: "ContractForm",
        components: { FwbInput, FwbButton, FwbSpinner},
        emits: ['submit'],
        setup(props, ctx) {
            const categories = ref([]);
            const evaluators = ref([]);
            const pageLoaded = ref(false);
            const contract = ref();
            const requestLoading = ref(false);
            const searchActive = ref(false)
            const route = useRoute();
            const addItem = () => {
                form.value.items.push({
                    category_id:categories.value[0].id,
                    type: '',
                    weight:'',
                    clear_weight:'',
                    description: '',
                })
            }
            const removeItem = (index) => {
                form.value.items = form.value.items.map((v,i) => {
                    if(i === index){
                        return {
                            ...v,
                            deleted:true
                        }
                    }
                    return v
                })
            }
            const onItemChange = (index) => {
                const item = form.value.items[index]
                const category = categories.value.find(v => v.id === item.category_id)
                if(category.name !== 'gold'){
                    item.weight = '';
                    item.clear_weight = '';
                    item.type = '';
                }
            }
            const sections = computed(() => {
                return [
                    {
                        fields: [
                            {icon: 'user', name: 'name', type: 'text', input: 'input', label: 'Անուն', required: true},
                            {icon: 'user', name: 'surname', type: 'text', input: 'input', label: 'Ազգանուն', required: true},
                            {icon: 'user', name: 'middle_name', type: 'text', input: 'input', label: 'Հայրանուն', required: false},
                            {icon: 'passport', name: 'passport', type: 'text', input: 'input', label: 'Անձնագիր', required: true},
                            {icon: 'passport', name: 'passport_given', type: 'text', input: 'input', label: 'Տրվ․', required: true},
                            {icon: 'calendar-days', name: 'dob', type: 'text', input: 'input', label: 'Ծնվ․', required: true},
                            {icon: 'location-dot', name: 'address', type: 'text', input: 'input', label: 'Հասցե', required: true},
                            {icon: 'phone', name: 'phone1', type: 'text', input: 'input', label: 'Հեռախոսահամար 1', required: true},
                            {icon: 'phone', name: 'phone2', type: 'text', input: 'input', label: 'Հեռախոսահամար 2', required: false},
                            {icon: 'envelope', name: 'email', type: 'email', input: 'input', label: 'էլ․ Հասցե', required: true},
                            {icon: 'building-columns', name: 'bank', type: 'text', input: 'input', label: 'Բանկ', required: false},
                            {icon: 'money-check', name: 'card', type: 'text', input: 'input', label: 'Քարտ', required: false},
                            {icon: 'comment', name: 'comment', input: 'textarea', label: 'Այլ', cols: 2, rows:4},
                        ]
                    },
                    {
                        fields: [
                            {icon: 'dollar-sign', name: 'worth', type: 'number', input: 'input', label: 'Արժեքը', required: true},
                            {icon: 'dollar-sign', name: 'given', type: 'number', input: 'input', label: 'Տրամադրվածը', required: true},
                            {icon: 'percent', name: 'rate', type: 'number', input: 'input', label: 'Տոկոսի չափը', required: true},
                            {icon: 'bolt', name: 'penalty', type: 'number', input: 'input', label: 'Տուգանքի չափը', required: true},
                            {icon: 'money-bill', name: 'one_time_payment', type: 'number', input: 'input', label: 'Միանվագ վճարը', disabled:true},
                            {icon: 'scale-unbalanced-flip', name: 'evaluator_id', input: 'select', label: 'Գնահատող', items: evaluators.value, fieldName: 'full_name', required: true},
                            {icon: 'calendar-days', name: 'deadline', input: 'datetime', label: 'Պայմանագրի ժամկետ', required: true},
                        ]
                    }
                ]
            })
            function createDateFromFormat(dateString) {
                const [day, month, year] = dateString.split('.');
                return new Date(year, month - 1, day);
            }
            getAxios().get('/api/edit-contract/' + route.params.id).then((res) => {
                pageLoaded.value = true
                categories.value = res.data.categories
                evaluators.value = res.data.evaluators
                contract.value = res.data.contract
                form.value = res.data.contract
                form.value.deadline = createDateFromFormat(res.data.contract.deadline)
                form.value.category_id = categories.value[0].id
                form.value.evaluator_id = evaluators.value[0].id
            })

            const searchValue = ref('')
            const searchTimeout = ref()
            const searchResults = ref([])
            const resetClient = () => {
                sections[0].fields.forEach((v) => {
                    form.value[v.name] = ''
                })
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
            const blurSearchInput = () => {
                setTimeout(() => {
                    searchActive.value = false
                },200)
            }
            const focusSearchInput = () => {
                searchActive.value = true
            }
            const initSearch = () => {
                if(searchValue.value){
                    getAxios().post('/api/get-clients',{
                        text:searchValue.value
                    }).then((res) => {
                        if(res.data.clients){
                            searchResults.value = res.data.clients
                        }
                    })
                }

            }
            const clientSelected = (client) => {
                form.value.name = client.name
                form.value.surname = client.surname
                form.value.middle_name = client.middle_name
                form.value.passport = client.passport
                form.value.address = client.address
                form.value.phone1 = client.phone1
                form.value.phone2 = client.phone2
                form.value.email = client.email
                form.value.comment = client.comment
                form.value.dob = client.dob
                form.value.passport_given = client.passport_given
            }
            const form = ref({})
            watch(() => form.value.given,(value) => {
                if(value >= 400000){
                    form.value.one_time_payment = value * 0.01 * 2
                }else{
                    form.value.one_time_payment = value * 0.01 * 2.5
                }

            },{
                immediate: true
            })
            const submitForm = (e) => {
                e.preventDefault()
                requestLoading.value = true
                getAxios().post('/api/update-contract', {...form.value}).then((res) => {
                    requestLoading.value = false
                    alertSuccess('Պայմանագիրը փոփոխված է')
                    router.push('/payments/' + res.data.contract.id)
                })
            }
            const isGold = (id) => {
                return categories.value?.find((v) => v.id === id)?.name === 'gold'
            }
            return {
                submitForm,
                form,
                numberSteps,
                clientSelected,
                searchValue,
                categories,
                search,
                searchActive,
                resetClient,
                isGold,
                contract,
                focusSearchInput,
                blurSearchInput,
                requestLoading,
                pageLoaded,
                searchResults,
                sections,
                addItem,
                removeItem,
                onItemChange
            }
        }
    }
)
</script>


<style lang="scss">
.contract-form-handler.flex {
    grid-template-columns: repeat(2, minmax(0, 1fr))
}

.mx-datepicker {
    display: block;

    .mx-input:disabled {
        color: #000 !important;
    }
}
</style>
