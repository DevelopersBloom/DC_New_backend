<template>
    <div class="w-full ">
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700">
            <div class="p-8" v-if="editable">
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
                                        <dt class="text-gray-500 text-xs dark:text-gray-400">{{value.name + '  ' + value.surname}} <span class="pl-2">{{value.passport}}</span></dt>
                                        <dd class="text-xs font-medium pt-1">{{(value.email || '')+ '  ' + (value.dob || '')}}</dd>
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

                            <div class="rounded-lg relative shadow p-4 pt-6 border border-gray-200 dark:border-gray-700">
                                <template v-if="index === 0">
                                    <button type="button" @click="resetClient" class="absolute right-2 top-2 text-gray-900 dark:text-white text-sm"><i class="fa fa-x"></i></button>
                                </template>
                                <div class="grid grid-cols-2 gap-4">
                                    <template v-for="field in section.fields">
                                        <div :class="{'col-span-2': field.cols === 2}">
                                            <label :for="'field_' + field.name"
                                                   class="block mb-2 text-xs font-medium text-gray-900 dark:text-white"><i :class="'pr-2 fa fa-' + field.icon"></i>{{ field.label }} <span v-if="field.required" class="text-red-500">*</span></label>
                                            <template v-if="field.input === 'input'">
                                                <template v-if="field.masked">
                                                    <div class="relative">
                                                        <div v-if="field.name === 'phone1' || field.name === 'phone2'" class="prep text-sm dark:text-white">+374</div>
                                                        <MaskInput autocomplete="off" :id="'field_' + field.name" :mask="field.mask" :placeholder="field.placeholder" v-model="form[field.name]"
                                                                   :required="field.required"
                                                                   class="bg-gray-50 border text-gray-900 text-sm rounded-lg
                                                                          focus:ring-blue-500 focus:border-blue-500 block
                                                                          w-full p-2.5 dark:bg-gray-700
                                                                          dark:placeholder-gray-400 dark:text-white
                                                                          dark:focus:ring-blue-500 dark:focus:border-blue-500"
                                                                   :class="[formErrors[field.name] ? 'border-red-600' : 'border-gray-300 dark:border-gray-600']"
                                                        />
                                                    </div>

                                                </template>
                                                <template v-else>
                                                    <input :disabled="field.disabled" autocomplete="off" :type="field.type"
                                                           :step="numberSteps[field.name]" :name="field.name"
                                                           :id="'field_' + field.name"
                                                           v-model="form[field.name]"
                                                           class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg
                               focus:ring-blue-500 focus:border-blue-500 block
                               w-full p-2.5 dark:bg-gray-700 dark:border-gray-600
                               dark:placeholder-gray-400 dark:text-white
                               dark:focus:ring-blue-500 dark:focus:border-blue-500"
                                                           placeholder=" " :required="field.required"/>
                                                </template>
                                            </template>
                                            <template v-else-if="field.input === 'textarea'">
                        <textarea v-model="form[field.name]" :rows="field.rows" :disabled="field.disabled" autocomplete="off"
                                  :name="field.name" :id="'field_' + field.name"
                                  class="block p-2.5 w-full text-sm text-gray-900 bg-gray-50 rounded-lg border border-gray-300 focus:ring-blue-500 focus:border-blue-500
                                  dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                                  :required="field.required"></textarea>
                                            </template>
                                            <template v-else-if="field.input === 'datetime'">
                                                <div class="flex">
                                                    <fwb-radio v-model="form.deadline_type" label="Ամիսներ" value="months" />
                                                    <fwb-radio v-model="form.deadline_type" label="Օրեր" value="days" />
                                                    <fwb-radio v-model="form.deadline_type" label="Օրացույց" value="calendar" />
                                                </div>
                                                <template v-if="form.deadline_type === 'days'">
                                                    <div class="flex items-center gap-4">
                                                        <input v-model="form.deadline_days" type="number" step="1" id="deadline_days" class="block p-2 text-gray-900 border border-gray-300 rounded-lg bg-gray-50 sm:text-xs focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                                                        <span class="dark:text-white">Օր</span>
                                                    </div>
                                                </template>
                                                <template v-else-if="form.deadline_type === 'months'">
                                                    <div class="flex items-center gap-4">
                                                        <input v-model="form.deadline_months" type="number" step="1" id="deadline_months" class="block p-2 text-gray-900 border border-gray-300 rounded-lg bg-gray-50 sm:text-xs focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                                                        <span class="dark:text-white">Ամիս</span>
                                                    </div>
                                                </template>
                                                <template v-else-if="form.deadline_type === 'calendar'">
                                                    <datepicker required type="date" :id="'field_' + field.name"
                                                                v-model:value="form[field.name]"></datepicker>
                                                </template>

                                            </template>

                                            <template v-else-if="field.input === 'select'">
                                                <select :disabled="field.disabled" :id="'field_' + field.name"
                                                        :name="field.name"
                                                        v-model="form[field.name]"
                                                        class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5
                                dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                                                    <option v-for="option in field.items" :value="option.id">{{option[field.fieldName]}}</option>
                                                </select>
                                            </template>
                                            <template v-else-if="field.input === 'cash'">
                                                <div class="flex w-min gap-4">
                                                    <fwb-radio v-model="form[field.name]" label="Կանխիկ" :value="true" />
                                                    <fwb-radio v-model="form[field.name]" label="Անկանխիկ" :value="false" />
                                                </div>
                                            </template>

                                        </div>
                                    </template>

                                    <template v-if="index === 0">
                                        <div class="col-span-2 border-t pt-2 border-gray-800 dark:border-gray-300">
                                            <div class="relative">
                                                <label class="block mb-2 text-xs font-medium text-gray-900 dark:text-white"><i class="pr-2 fa fa-file"></i>Ֆայլեր</label>
                                                <fwb-file-input id="clientFiles" @change="onClientFilesChanged" multiple />
                                            </div>

                                            <div class="grid grid-cols-12">
                                                <div v-for="(file,idx) in clientFiles" :key="file" class="col-span-3 p-4">
                                                    <div class="relative rounded-md image-preview-handler border shadow dark:border-gray-700">
                                                        <template v-if="isImage(file)">
                                                            <img  class="preview-image rounded-md" :src="getImageSrc(file)" alt="">
                                                        </template>
                                                        <template v-else>
                                                            <div class="preview-image">
                                                                <span class="preview-file-handler"><i class="fa fa-file"></i></span>
                                                            </div>
                                                        </template>

                                                        <button @click="removeClientFile(idx)" type="button" class="file-remove-button text-lg absolute left-full top-0 px-1"><i class="text-red-500 fa fa-times"></i></button>
                                                    </div>
                                                    <p class="text-xs dark:text-gray-300 mt-1">{{file.name}}</p>

                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                    <template v-else>
                                        <div class="col-span-2 border-t pt-2 border-gray-800 dark:border-gray-300">

                                            <div class="relative">
                                                <label class="block mb-2 text-xs font-medium text-gray-900 dark:text-white"><i class="pr-2 fa fa-file"></i>Ֆայլեր</label>
                                                <fwb-file-input id="contractFiles" @change="onContractFilesChanged" multiple />
                                            </div>

                                            <div class="grid grid-cols-12">
                                                <div v-for="(file,idx) in contractFiles" :key="file" class="col-span-3 p-4">
                                                    <div class="relative rounded-md image-preview-handler border shadow dark:border-gray-700">
                                                        <template v-if="isImage(file)">
                                                            <img  class="preview-image rounded-md" :src="getImageSrc(file)" alt="">
                                                        </template>
                                                        <template v-else>
                                                            <div class="preview-image">
                                                                <span class="preview-file-handler"><i class="fa fa-file"></i></span>
                                                            </div>
                                                        </template>

                                                        <button @click="removeContractFile(idx)" type="button" class="file-remove-button text-lg absolute left-full top-0 px-1"><i class="text-red-500 fa fa-times"></i></button>
                                                    </div>
                                                    <p class="text-xs dark:text-gray-300 mt-1">{{file.name}}</p>

                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-span-2 border-t-2 pt-2 border-orange-500">
                                            <div class="block mb-2 text-xs font-medium text-gray-900 dark:text-white">
                                                <i class="pr-2 fa fa-list"></i>
                                                Իրեր(<span class="text-orange-700 dark:text-orange-400 px-1">{{items.length}}</span>)
                                            <div class="flex flex-col gap-3 mt-2">
                                                <template v-for="(item,index) in items">
                                                    <div class="rounded-md relative shadow p-4 pt-2 border border-orange-500">
                                                        <p>{{index+1}}.</p>
                                                        <div class="absolute top-1 right-1" v-if="items.length > 1"><i class="fa fa-trash text-red-500 cursor-pointer" @click="removeItem(index)"></i></div>
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
                                                                    <fwb-input step="0.01" :id="'weight_' + index" :name="'weight_' + index" v-model="item.weight" type="number"></fwb-input>
                                                                </div>
                                                                <div>
                                                                    <label class="block mb-2 text-xs font-medium text-gray-900 dark:text-white" :for="'clear_weight_' + index"><i class="fa fa-scale-unbalanced pr-2"></i>Մաքուր քաշը</label>
                                                                    <fwb-input step="0.01" :id="'clear_weight_' + index" :name="'clear_weight_' + index" v-model="item.clear_weight" type="number"></fwb-input>
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
                    <button type="submit"
                            class="block ms-auto mt-6 focus:outline-none text-white bg-green-700 hover:bg-green-800
                    focus:ring-4 focus:ring-green-300 font-medium
                    rounded-lg text-xs px-3 py-2 mr-2 mb-2 dark:bg-green-600
                    dark:hover:bg-green-700 dark:focus:ring-green-800">Ավելացնել</button>

                </form>



            </div>
            <template v-else>
                <dl class=" h-full border-gray-200 dark:border-gray-700 rounded-lg  text-gray-900 p-4 dark:text-gray-100">
                    <div class="flex justify-end mb-2">
                        <fwb-button color="default" size="sm" :href="'/profile/' + user?.id" tag="router-link">Հաճ․ Էջ</fwb-button>
                    </div>

                    <template v-for="(section,index) in staticSections">
                        <div class="grid grid-cols-2 gap-x-2 gap-y-2" :class="{'pb-4':index === 0}">
                            <template v-for="field in section.fields">
                                <div :class="[field.cols ? 'col-span-' + field.cols : '']">
                                    <div class="flex flex-col pb-1 border border-gray-200 dark:border-gray-700 rounded-lg p-2">
                                        <dt class="mb-0.5 text-gray-500 text-xs dark:text-gray-400">
                                            <i class="fa pr-2"
                                               :class="'fa-' + field.icon"></i>
                                            {{ field.label }}
                                        </dt>
                                        <dd class="text-sm pl-5 font-semibold" v-if="field.makeMoney">{{ makeMoney(form[field.name],true) || '-' }} <Dram/></dd>
                                        <dd class="text-sm pl-5 font-semibold" v-else>{{ form[field.name] || '-' }}</dd>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>
                    <template v-if="!mini">
                        <div class="mt-4">
                            <label class="block mb-2 text-sm font-medium text-gray-900 dark:text-white"><i class="pr-2 fa fa-list"></i>Իրեր</label>
                            <div class="border dark:border-gray-700 border-gray-200 rounded-lg shadow p-2">
                                <div class="grid grid-cols-12 gap-4">
                                    <div v-for="item in contract.items" class="col-span-4">
                                        <div class="border border-orange-500 rounded-lg shadow p-2">
                                            <div class="grid grid-cols-2 gap-2">
                                                <div>
                                                    <span class="text-xs text-gray-500 dark:text-gray-400"><i class="fa fa-list pr-2 "></i>Տեսակը</span>
                                                    <p class="text-gray-900 pl-5 dark:text-gray-100 text-sm">{{item.category.title}}</p>
                                                </div>
                                                <div>
                                                    <span class="text-xs text-gray-500 dark:text-gray-400"><i class="fa fa-comment pr-2"></i>Նկարագրություն</span>
                                                    <p class="text-gray-900 pl-5 dark:text-gray-100 text-sm">{{item.description || '-'}}</p>
                                                </div>
                                                <div v-if="item.type">
                                                    <span class="text-xs text-gray-500 dark:text-gray-400"><i class="fa fa-scale-balanced pr-2"></i>Հարգը</span>
                                                    <p class="text-gray-900 pl-5 dark:text-gray-100 text-sm">{{item.type}}</p>
                                                </div>
                                                <div v-if="item.weight">
                                                    <span class="text-xs text-gray-500 dark:text-gray-400"><i class="fa fa-scale-unbalanced-flip pr-2"></i>Քաշը</span>
                                                    <p class="text-gray-900 pl-5 dark:text-gray-100 text-sm">{{item.weight}}</p>
                                                </div>
                                                <div v-if="item.clear_weight">
                                                    <span class="text-xs text-gray-500 dark:text-gray-400"><i class="fa fa-scale-unbalanced pr-2"></i>Մաքուր քաշը</span>
                                                    <p class="text-gray-900 pl-5 dark:text-gray-100 text-sm">{{item.clear_weight}}</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="mt-4">
                            <label class="block mb-2 text-sm font-medium text-gray-900 dark:text-white"><i class="pr-2 fa fa-file"></i>Ֆայլեր</label>
                            <div class="border dark:border-gray-700 border-gray-200 rounded-lg shadow p-2">
                                <div class="grid grid-cols-12 gap-4">
                                    <div v-if="contract.files.length" v-for="file in contract.files" class="col-span-6 md:col-span-4 lg:col-span-3">
                                        <div class="overflow-hidden ">
                                            <div class="border file-preview relative rounded-lg overflow-hidden">
                                                <template v-if="isImage(file)">
                                                    <img class="profile-file-image " :src="'/storage/contract/files/' + file.name" alt="">
                                                </template>
                                                <template v-else>
                                                    <div class="profile-file-other">
                                                        <i class="fa fa-file"></i>
                                                    </div>
                                                </template>
                                                <div class="download-desk-handler">
                                                    <a class="z-10 text-white download-button text-xl" :href="'/storage/contract/files/' + file.name" :download="file.original_name"><i class="fa  fa-download"></i></a>
                                                </div>
                                            </div>

                                            <p class="text-sm break-words">{{file.original_name}}</p>
                                        </div>

                                    </div>
                                    <div v-else class="col-span-12">
                                        <p class="text-sm text-gray-500 dark:text-gray-400">Ֆայլեր չկան</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </template>

                </dl>

            </template>
        </section>

    </div>
</template>

<script>
import getAxios from "../../axios";
import { computed, defineComponent, onMounted, reactive, ref, watch } from "vue";

const numberSteps = {
    worth: 100,
    given: 100,
    rate: 0.01,
    penalty: 0.01,
}
const staticSections = [
    {
        fields: [
            {icon: 'user', name: 'name', type: 'text', input: 'input', label: 'Անուն', required: true},
            {icon: 'user', name: 'surname', type: 'text', input: 'input', label: 'Ազգանուն', required: true},
            {icon: 'user', name: 'middle_name', type: 'text', input: 'input', label: 'Հայրանուն', required: true},
            {icon: 'passport', name: 'passport', type: 'text', input: 'input', label: 'Անձնագիր', required: true},
            {icon: 'location-dot', name: 'address', type: 'text', input: 'input', label: 'Հասցե', required: true},
            {icon: 'phone', name: 'phone1', type: 'text', input: 'input', label: 'Հեռախոսահամար 1', required: true},
            {icon: 'phone', name: 'phone2', type: 'text', input: 'input', label: 'Հեռախոսահամար 2', required: true},
            {icon: 'envelope', name: 'email', type: 'email', input: 'input', label: 'էլ․ Հասցե', required: true},
            {icon: 'building-columns', name: 'bank', type: 'text', input: 'input', label: 'Բանկ', required: false},
            {icon: 'money-check', name: 'card', type: 'text', input: 'input', label: 'Քարտ', required: false},
            {icon: 'comment', name: 'comment', input: 'textarea', label: 'Այլ', cols: 2, rows:4},
        ]
    },
    {
        fields: [
            {icon: 'hashtag', name: 'id', type: 'number', input: 'input', label: 'ID', required: true},
            {icon: 'hashtag', name: 'ADB_ID', type: 'number', input: 'input', label: 'N', required: true},
            {icon: 'dollar-sign', name: 'worth', type: 'number', input: 'input', label: 'Արժեքը', required: true, makeMoney: true},
            {icon: 'dollar-sign', name: 'given', type: 'number', input: 'input', label: 'Տրամադրվածը', required: true, makeMoney: true},
            {icon: 'percent', name: 'rate', type: 'number', input: 'input', label: 'Տոկոսի չափը', required: true},
            {icon: 'bolt', name: 'penalty', type: 'number', input: 'input', label: 'Տուգանքի չափը', required: true},
            {icon: 'money-bill', name: 'one_time_payment', type: 'number', input: 'input', label: 'Միանվագ վճարը'},
            {icon: 'scale-unbalanced-flip', name: 'evaluator_title', input: 'select', label: 'Գնահատող',  fieldName: 'full_name'},
            {icon: 'calendar-days', name: 'date', input: 'datetime', label: 'Պայմանագրի Կնքում', required: true},
            {icon: 'calendar-days', name: 'close_date', input: 'datetime', label: 'Պայմանագրի Մարում', required: true},
            {icon: 'calendar-days', name: 'deadline', input: 'datetime', label: 'Պայմանագրի ժամկետ', required: true},
            {icon: 'dollar-sign', name: 'collected', type: 'number', input: 'input', label: 'Վճարված', makeMoney: true},
        ]
    }
]
const goldRates = [
    {
        minValue: 5000,
        maxValue: 20000,
        rate:0.23
    },
    {
        minValue: 21000,
        maxValue: 50000,
        rate: 0.20
    },
    {
        minValue: 51000,
        maxValue: 100000,
        rate: 0.18
    },
    {
        minValue: 101000,
        maxValue: 200000,
        rate: 0.17
    },
    {
        minValue: 201000,
        maxValue: 500000,
        rate: 0.16
    },
    {
        minValue: 501000,
        maxValue: 750000,
        rate: 0.15
    },
    {
        minValue: 751000,
        maxValue: 1500000,
        rate: 0.14
    },
    {
        minValue: 1501000,
        maxValue: 2500000,
        rate: 0.13
    },
    {
        minValue: 2501000,
        maxValue: 3500000,
        rate: 0.12
    },
    {
        minValue: 3501000,
        maxValue: 5000000,
        rate: 0.11
    },
    {
        minValue: 5001000,
        maxValue: null,
        rate: 0.10
    },

];
const carRates = [
    {
        minValue: 300000,
        maxValue: 700000,
        rate: 0.18
    },
    {
        minValue: 701000,
        maxValue: 1000000,
        rate: 0.16
    },
    {
        minValue: 1001000,
        maxValue: 2000000,
        rate: 0.15
    },
    {
        minValue: 2001000,
        maxValue: 3500000,
        rate: 0.14
    },
    {
        minValue: 2501000,
        maxValue: 3500000,
        rate: 0.14
    },
    {
        minValue: 3501000,
        maxValue: 5000000,
        rate: 0.13
    },
    {
        minValue: 5001000,
        maxValue: 10000000,
        rate: 0.12
    },
    {
        minValue: 10001000,
        maxValue: null,
        rate: 0.11
    },
]
import { FwbButton, FwbFileInput, FwbInput, FwbRadio, FwbSelect, FwbTextarea } from 'flowbite-vue'
import { makeMoney } from "../../calc";
import Dram from "@/components/icons/Dram.vue";
import IMask from 'imask';
export default defineComponent(
    {
        name: "ContractForm", methods: { makeMoney },
        props: {
            editable: {
                type: Boolean,
                default: false,
            },
            contract: Object,
            flex: {
                type: Boolean,
                default: true,
            },
            mini: {
                type: Boolean, default: false
            }
        },
        components: { Dram, FwbInput, FwbTextarea, FwbSelect, FwbButton, FwbFileInput , FwbRadio},
        emits: ['submit'],
        setup(props, ctx) {
            const categories = ref([]);
            const evaluators = ref([]);
            const searchActive = ref(false)
            const clientFiles = ref([])
            const contractFiles = ref([])
            const formErrors = ref({})
            const items = ref([{
                category_id:'',
                type: '',
                weight:'',
                clear_weight:'',
                description: '',
            }])
            const addItem = () => {
                items.value.push({
                    category_id:categories.value[0].id,
                    type: '',
                    weight:'',
                    clear_weight:'',
                    description: '',
                })
            }
            const removeItem = (index) => {
                items.value = items.value.filter((v,i) => i !== index)
            }
            const onItemChange = (index) => {
                const item = items.value[index]
                const category = categories.value.find(v => v.id === item.category_id)
                if(category.name !== 'gold'){
                    item.weight = '';
                    item.clear_weight = '';
                    item.type = '';
                }
            }
            const isBetween = (value,minValue,maxValue) => {
                if(maxValue){
                    return value >= minValue && value <= maxValue
                }else{
                    return value >= minValue
                }
            }
            const setRate = () => {
                if(props.editable){
                    const firstCategory = firstCategoryComputed.value
                    if(firstCategory?.name === 'gold' || firstCategory?.name === 'car'){
                        const arr = firstCategory.name === 'gold' ? goldRates : carRates
                        let rate = 0.4;
                        for(let i = 0; i < arr.length; i++){
                            let amount = form.value.given || 0
                            if(isBetween(amount,arr[i].minValue,arr[i].maxValue)){
                                rate = arr[i].rate
                                break;
                            }
                        }
                        form.value.rate = rate
                    }else{
                        form.value.rate = 0.4
                    }
                }

            }
            const firstCategoryComputed = computed(() => {
                const firstCategoryId = items.value[0].category_id;
                return categories.value?.find(v => v.id === firstCategoryId)
            })
            const sections = computed(() => {
                return [
                    {
                        fields: [
                            {icon: 'user', name: 'name', type: 'text', input: 'input', label: 'Անուն', required: true},
                            {icon: 'user', name: 'surname', type: 'text', input: 'input', label: 'Ազգանուն', required: true},
                            {icon: 'user', name: 'middle_name', type: 'text', input: 'input', label: 'Հայրանուն', required: false},
                            {icon: 'passport', name: 'passport', type: 'text', input: 'input', label: 'Անձնագիր', required: true},
                            {icon: 'passport', name: 'passport_given', type: 'text', input: 'input', label: 'Տրվ․', required: true},
                            {icon: 'calendar-days', name: 'dob', type: 'text', input: 'input', label: 'Ծնվ․', required: true, masked: true, mask:'##.##.####', placeholder: '00.00.0000'},
                            {icon: 'location-dot', name: 'address', type: 'text', input: 'input', label: 'Հասցե', required: true},
                            {icon: 'phone', name: 'phone1', type: 'text', input: 'input', label: 'Հեռախոսահամար 1', required: true, masked: true, mask:'## ######', placeholder: '## ######'},
                            {icon: 'phone', name: 'phone2', type: 'text', input: 'input', label: 'Հեռախոսահամար 2', required: false, masked: true, mask:'## ######', placeholder: '## ######'},
                            {icon: 'envelope', name: 'email', type: 'email', input: 'input', label: 'էլ․ Հասցե', required: true},
                            {icon: 'building-columns', name: 'bank', type: 'text', input: 'input', label: 'Բանկ', required: false},
                            {icon: 'money-check', name: 'card', type: 'text', input: 'input', label: 'Քարտ', required: false, masked:true, mask:'#### #### #### ####', placeholder:'#### #### #### ####'},
                            {icon: 'comment', name: 'comment', input: 'textarea', label: 'Այլ', cols: 2, rows:4},
                        ]
                    },
                    {
                        fields: [
                            {icon: 'dollar-sign', name: 'worth', type: 'number', input: 'input', label: 'Արժեքը', required: true},
                            {icon: 'dollar-sign', name: 'given', type: 'number', input: 'input', label: 'Տրամադրվածը', required: true},
                            {icon: 'percent', name: 'rate', type: 'number', input: 'input', label: 'Տոկոսի չափը', required: true,disabled:true},
                            {icon: 'bolt', name: 'penalty', type: 'number', input: 'input', label: 'Տուգանքի չափը', required: true,disabled:true},
                            {icon: 'money-bill', name: 'one_time_payment', type: 'number', input: 'input', label: 'Միանվագ վճարը', disabled:true},
                            {icon: 'scale-unbalanced-flip', name: 'evaluator_id', input: 'select', label: 'Գնահատող', items: evaluators.value, fieldName: 'full_name', required: true},
                            {icon: 'calendar-days', name: 'deadline', input: 'datetime', label: 'Պայմանագրի ժամկետ', required: true},
                            {icon: 'cash-register', name: 'cash', input: 'cash', label: 'Վճարման տեսակը', required: true},
                        ]
                    }
                ]
            })
            if(props.editable){
                let url = '/api/get-categories'
                if(localStorage.getItem('client_id')){
                    const client_id = localStorage.getItem('client_id')
                    localStorage.removeItem('client_id')
                    url = '/api/get-categories?client_id=' + client_id
                }
                getAxios().get(url)
                .then((res) => {
                    categories.value = res.data.categories
                    evaluators.value = res.data.evaluators
                    if(res.data.client){
                        clientSelected(res.data.client)
                    }
                    form.value.category_id = categories.value[0].id
                    items.value[0].category_id = categories.value[0].id
                    form.value.evaluator_id = evaluators.value[0].id
                })
            }

            const searchValue = ref('')
            const searchTimeout = ref()
            const user = computed(() => props.contract?.client)
            const searchResults = ref([])
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
            const onClientFilesChanged = (e) => {
                Array.from(e.target.files).forEach((v) => {
                    clientFiles.value.push(v)
                })
            }
            const onContractFilesChanged = (e) => {
                Array.from(e.target.files).forEach((v) => {
                    contractFiles.value.push(v)
                })
            }
            const getImageSrc = (file) => {
                if(file){
                    return URL.createObjectURL(file)
                }
                return ''
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
            const removeClientFile = (index) => {
                clientFiles.value = clientFiles.value.filter((v,i) => i !== index)
            }
            const removeContractFile = (index) => {
                contractFiles.value = contractFiles.value.filter((v,i) => i !== index)
            }
            const isImage = (file) => {
                return file && file['type'].split('/')[0] === 'image';
            }
            const clientSelected = (client) => {
                form.value.name = client.name
                form.value.surname = client.surname
                form.value.middle_name = client.middle_name
                form.value.passport = client.passport
                form.value.address = client.address
                setMaskInputValue('phone1',client.phone1)
                setMaskInputValue('phone2',client.phone2)
                form.value.email = client.email
                form.value.comment = client.comment
                setMaskInputValue('dob',client.dob)
                form.value.passport_given = client.passport_given
                form.value.bank = client.bank
                setMaskInputValue('card',client.card)
            }
            const setMaskInputValue = (name,value) => {
                if((name === 'phone1' || name === 'phone2') && value){
                    value = value.substring(1)
                }
                if(value){
                    value = value.replace(/\s/g, "");
                }

                let inputElement = document.getElementById('field_' + name)
                if(value){
                    inputElement.value = value
                }else{
                    inputElement.value = ''
                }
                let inputEvent = new Event('input', {
                    bubbles: true,
                    cancelable: true,
                });
                inputElement.dispatchEvent(inputEvent);
            }
            const resetMaskInput = (name) => {
                let inputElement = document.getElementById('field_' + name)
                inputElement.value = ''
                let inputEvent = new Event('input', {
                    bubbles: true,
                    cancelable: true,
                });
                inputElement.dispatchEvent(inputEvent);
            }
            const resetClient = () => {
                sections.value[0].fields.forEach((v) => {
                    if(v.masked){
                        resetMaskInput(v.name)
                    }
                    form.value[v.name] = ''
                })

            }
            const form = ref({
                name: '',
                surname: '',
                middle_name: '',
                passport: '',
                address: '',
                phone1: '',
                phone2: '',
                email: '',
                bank: '',
                card: '',
                passport_given: '',
                dob: '',
                cash: true,
                comment: '',
                worth: '',
                given: '',
                rate: 0.4,
                penalty: 0.13,
                one_time_payment: '',
                deadline: new Date(),
                deadline_type: 'months',
                deadline_days: 10,
                deadline_months: 5,
                category_id:null,
                evaluator_id:null,
                date: new Date(),
            })
            const isGold = (id) => {
                return categories.value?.find((v) => v.id === id)?.name === 'gold'
            }
            watch(() => firstCategoryComputed.value,(value) => {
                setRate()
            })
            watch(() => props.contract,(value) => {
                if(value){
                    form.value = value
                }
            },{
                immediate: true
            })
            watch(() => form.value.given,(value) => {
                if(props.editable){
                    if(value >= 400000){
                        form.value.one_time_payment = Math.round(value * 0.01 * 2 / 10 ) * 10
                    }else{
                        form.value.one_time_payment = Math.round(value * 0.01 * 2.5 / 10 ) * 10
                    }
                }
                setRate()

            },{
                immediate: true
            })
            var date = new Date()
            if (!form.value?.id) {
                var deadlineDef = new Date(date.setMonth(date.getMonth() + 5));
                form.value.deadline = deadlineDef
            }

            const submitForm = (e) => {
                e.preventDefault()
                if(form.value.card.length && form.value.card.length !== 19){
                    formErrors.value.card = true
                }else{
                    const formData = new FormData();
                    Object.entries(form.value).forEach(([v,i]) => {
                        if(['date','deadline'].includes(v)){
                            if(i){
                                let date = (new Date(i)).toUTCString()
                                formData.append(v,date)
                            }

                        }else{
                            if(i){
                                formData.append(v,i)
                            }

                        }
                    })
                    clientFiles.value.forEach((v) => {
                        formData.append('clientFiles[]', v);
                    })
                    contractFiles.value.forEach((v) => {
                        formData.append('contractFiles[]', v);
                    })
                    items.value.forEach((v,i) => {
                        formData.append('items['+ i +'][category_id]', v.category_id);
                        formData.append('items['+ i +'][weight]', v.weight);
                        formData.append('items['+ i +'][clear_weight]', v.clear_weight);
                        formData.append('items['+ i +'][description]', v.description);
                        formData.append('items['+ i +'][type]', v.type);
                    })
                    ctx.emit('submit', formData)
                }

            }
            return {
                submitForm,
                form,
                numberSteps,
                clientSelected,
                searchValue,
                categories,
                search,
                date,
                searchActive,
                resetClient,
                focusSearchInput,
                blurSearchInput,
                user,
                removeClientFile,
                removeContractFile,
                searchResults,
                sections,
                staticSections,
                clientFiles,
                contractFiles,
                isImage,
                getImageSrc,
                onClientFilesChanged,
                onContractFilesChanged,
                items,
                isGold,
                addItem,
                removeItem,
                onItemChange,
                formErrors
            }
        }
    }
)
</script>


<style lang="scss" scoped>
.contract-form-handler.flex {
    grid-template-columns: repeat(2, minmax(0, 1fr))
}
.preview-image{
    width: 100%;
    height:150px;
    object-fit: cover;
}
.preview-file-handler{
    position: absolute;
    left: 50%;
    top: 50%;
    transform:translate(-50%, -50%)
}
.mx-datepicker {
    display: block;

    .mx-input:disabled {
        color: #000 !important;
    }
}
.prep{
    position: absolute;
    top: 50%;
    transform:translateY(-50%);
    left: 10px;
}
#field_phone1,#field_phone2{
    padding-left: 50px;
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
