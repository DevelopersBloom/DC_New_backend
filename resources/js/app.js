import './bootstrap';
import * as Vue from 'vue'
import router from "./router"
import App from './components/App.vue'
import store from "./store";
const app = Vue.createApp(App)
import DatePicker from 'vue-datepicker-next';
import 'vue-datepicker-next/index.css';
import VueApexCharts from "vue3-apexcharts";
import { MaskInput } from 'vue-3-mask';
app.use(router)
app.use(store)
app.use(VueApexCharts)
app.component('MaskInput', MaskInput);
app.component('datepicker',DatePicker )
app.mount("#app")

