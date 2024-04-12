<template>
    <div class="dark:text-gray-400 dark:bg-gray-800 dark:border-gray-600 border-gray-200 rounded-lg shadow-sm bg-white border text-sm text-gray-500">
        <div class="p-3">
            <ul>
                <li v-for="(item,index) in contract.items">{{(index+1)}}. {{item.category.title}}</li>
            </ul>
        </div>
        <div data-popper-arrow></div>
    </div>

</template>
<script>
export default {
    name: "CategoryCard",
    props: {
        contract: Object
    },
    setup(props){
    }
}
</script>
<style scoped>

</style>
