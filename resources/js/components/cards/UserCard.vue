<template>
  <div class=" dark:text-gray-400 dark:bg-gray-800 dark:border-gray-600 border-gray-200 rounded-lg shadow-sm bg-white border text-sm text-gray-500">
    <div class="p-3">
      <div class="flex items-center justify-between mb-2">
          <p class="text-sm font-semibold leading-none text-gray-900 dark:text-white">
              <p>{{user?.name + ' ' + user?.surname}}</p>
          </p>
        <div>
          <router-link :to="'profile/' + user?.id" type="button"
                       class="text-white bg-blue-700 hover:bg-blue-800 focus:ring-4
                       focus:ring-blue-300 font-medium rounded-lg text-xs px-3 py-1.5
                       dark:bg-blue-600 dark:hover:bg-blue-700 focus:outline-none dark:focus:ring-blue-800">Էջ</router-link>
        </div>
      </div>

      <ul class="flex text-sm">
        <li class="mr-2">
          <p>
            <span class="font-semibold text-gray-900 text-xs dark:text-white">{{user?.contracts_count}}</span>
            <span class="font-normal pl-1 text-xs">Պայմանագիր</span>
          </p>
        </li>
      </ul>
    </div>
    <div data-popper-arrow></div>
  </div>

</template>
<script>
export default {
  name: "UserCard",
  props: {
      user: Object
  },
  setup(props){
  }
}
</script>
<style scoped>

</style>
