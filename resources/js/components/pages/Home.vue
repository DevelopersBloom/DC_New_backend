<template>
    <div class="container mx-auto pt-10 p-4 pb-6">
        <h1 class="mb-4 text-2xl font-bold text-gray-900 dark:text-white">Նոր պայմանագիր</h1>
        <contract-form @submit="submitForm" editable ></contract-form>
    </div>
</template>
<script>
import ContractForm from "../contract/ContractForm.vue";
import { alertSuccess } from "@/calc";
import router from "@/router";
import getAxios from "@/axios";
export default {
    name: "Home",
    components: {ContractForm},
    setup (){
        const submitForm = (form) => {
            const headers = { 'Content-Type': 'multipart/form-data'};
            getAxios().post('/api/create-contract', form,{headers}).then((res) => {
                alertSuccess('Պայմանագիրը հաջողությամբ ավելացվել է')
                router.push('/payments/' + res.data.contract.id)
            })
        }
        return {
            submitForm
        }
    }
}
</script>

<style scoped>

</style>
