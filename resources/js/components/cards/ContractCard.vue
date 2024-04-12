<template>
    <div class="grid grid-cols-2 gap-1">
        <template v-for="field in contractFields">
            <div :class="{'col-span-2': field.cols === 2}">
                <label :for="'field_' + field.name"
                       class="flex nowrap mb-1 text-xxs font-light text-gray-500 dark:text-gray-400"><i
                    :class="'pr-2 fa fa-' + field.icon"></i>{{ field.label }}</label>
                <p class="text-xxs pl-3 font-semibold">{{field.makeMoney ? makeMoney(contract[field.name]) : contract[field.name]}}</p>
            </div>
        </template>
    </div>
</template>
<script>
import { makeMoney } from "../../calc";

const contractFields = [
    {icon: 'dollar-sign', name: 'worth', type: 'number', input: 'input', label: 'Արժեքը', required: true, makeMoney:true},
    {icon: 'dollar-sign', name: 'given', type: 'number', input: 'input', label: 'Տրամադրվածը', required: true, makeMoney:true},
    {icon: 'percent', name: 'rate', type: 'number', input: 'input', label: 'Տոկոսի չափը', required: true},
    {icon: 'bolt', name: 'penalty', type: 'number', input: 'input', label: 'Տուգանքի չափը', required: true},
    {icon: 'money-bill', name: 'one_time_payment', type: 'number', input: 'input', label: 'Միանվագ վճարը', makeMoney:true},
    {icon: 'comment', name: 'comment', input: 'textarea', label: 'Նկարագրություն', rows: 2},
    {icon: 'text-height', name: 'category_title', input: 'select', label: 'Տեսակը', items: []},
    {icon: 'calendar-days', name: 'deadline', input: 'datetime', label: 'Պայմանագրի ժամկետ', required: true},
]
export default {
    name: "ContractCard", methods: { makeMoney },
    props: {
        contract: Object
    },
    setup(props,ctx){
        return {
            contractFields
        }
    }
}
</script>
<style scoped>

</style>
