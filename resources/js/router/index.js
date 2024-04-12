import * as VueRouter from 'vue-router';
import store from "../store";

// login route
import Login from "../components/pages/auth/Login.vue";

// layout components
import Layout from "../components/layouts/Layout.vue";
import AdminLayout from "../components/layouts/AdminLayout.vue";
import ConfigLayout from "../components/layouts/ConfigLayout.vue";

// pages
import Home from "../components/pages/Home.vue";
import EditContract from "../components/pages/EditContract.vue";
import List from "../components/pages/List.vue";
import TodaysList from "../components/pages/TodaysList.vue";
import ClientsList from "../components/pages/ClientsList.vue";
import Discussions from "../components/pages/Discussions.vue";
import PaymentsPage from "../components/pages/PaymentsPage.vue";
import Profile from "../components/pages/Profile.vue";
import Costs from "../components/pages/Costs.vue";
import Cashbox from "../components/pages/Cashbox.vue";

// admin components
import AdminUsersList from "../components/pages/admin/users/AdminUsersList.vue";
import AdminEvaluatorsList from "../components/pages/admin/evaluators/AdminEvaluatorsList.vue";
import AdminEvaluatorsAdd from "../components/pages/admin/evaluators/AdminEvaluatorsAdd.vue";
import AdminEvaluatorsEdit from "../components/pages/admin/evaluators/AdminEvaluatorsEdit.vue";
import AdminUsersAdd from "../components/pages/admin/users/AdminUsersAdd.vue";
import AdminPawnshopsList from "../components/pages/admin/pawnshops/AdminPawnshopsList.vue";
import AdminPawnshopsAdd from "../components/pages/admin/pawnshops/AdminPawnshopsAdd.vue";
import AdminCategoriesList from "../components/pages/admin/categories/AdminCategoriesList.vue";
import AdminCategoriesAdd from "../components/pages/admin/categories/AdminCategoriesAdd.vue";
import AdminStatistics from "../components/pages/admin/AdminStatistics.vue";
import AdminUsersEdit from "../components/pages/admin/users/AdminUsersEdit.vue";
import AdminPawnshopsEdit from "../components/pages/admin/pawnshops/AdminPawnshopsEdit.vue";
import AdminCategoriesEdit from "../components/pages/admin/categories/AdminCategoriesEdit.vue";
import AdminDiscounts from "../components/pages/admin/AdminDiscounts.vue";
import AdminPawnshopsCashbox from "../components/pages/admin/pawnshops/AdminPawnshopsCashbox.vue";
import AdminReports from "../components/pages/admin/reports/AdminReports.vue";

// config components
import DealsFix from "../components/pages/config/DealsFix.vue";
import SetBankCashbox from "../components/pages/config/SetBankCashbox.vue";
import SetOrders from "../components/pages/config/SetOrders.vue";

const routes = [
    {
        path: '/',
        component: Layout,
        redirect: '/list',
        meta: { requiresAuth: true },
        children: [
            {
                path: '/home',
                component: Home,
                meta: { roles: ['admin','user'] }
            },
            {
                path: '/edit-contract/:id',
                component: EditContract,
                meta: { roles: ['admin'] }
            },
            {
                path: '/list',
                component: List,
                meta: { roles: ['admin', 'user'] }
            },
            {
                path: '/costs',
                component: Costs,
                meta: { roles: ['admin', 'user'] }
            },
            {
                path: '/cashbox',
                component: Cashbox,
                meta: { roles: ['admin', 'user'] }
            },
            {
                path: '/todays-list',
                component: TodaysList,
                meta: { roles: ['admin','user']}
            },
            {
                path: '/users',
                component: ClientsList,
                meta: { roles: ['admin','user'] }
            },
            {
                path: '/discussions',
                component: Discussions,
                meta: { roles: ['admin','user'] }
            },
            {
                path: '/profile/:id',
                component: Profile,
                meta: { roles: ['admin','user'] }
            },
            {
                path: '/payments/:id',
                component: PaymentsPage,
                meta: { roles: ['admin','user'] }
            }
        ]
    },
    {
        path: '/set-config',
        component: ConfigLayout,
        redirect: '/config',
        children:[
            {
                path: '/config',
                component: DealsFix,
                meta: { requiresAuth: true, roles: ['admin', 'user'] }
            },
            {
                path: '/set-bank-cashbox',
                component: SetBankCashbox,
                meta: { requiresAuth: true, roles: ['admin', 'user'] }
            },
            {
                path: '/set-orders',
                component: SetOrders,
                meta: { requiresAuth: true, roles: ['admin', 'user'] }
            }
        ]
    },
    {
        path: '/dashboard',
        component: AdminLayout,
        redirect: '/admin/users',
        children: [
            {
                path: '/admin/users',
                component: AdminUsersList,
                meta: { requiresAuth: true, roles: ['admin'] }
            },
            {
                path: '/admin/reports',
                component: AdminReports,
                meta: { requiresAuth: true, roles: ['admin'] }
            },
            {
                path: '/admin/pawnshops',
                component: AdminPawnshopsList,
                meta: { requiresAuth: true, roles: ['admin'] }
            },
            {
                path: '/admin/categories',
                component: AdminCategoriesList,
                meta: { requiresAuth: true, roles: ['admin'] }
            },
            {
                path: '/admin/evaluators',
                component: AdminEvaluatorsList,
                meta: { requiresAuth: true, roles: ['admin'] }
            },
            {
                path: '/admin/statistics',
                component: AdminStatistics,
                meta: { requiresAuth: true, roles: ['admin'] }
            },
            {
                path: '/admin/discounts',
                component: AdminDiscounts,
                meta: { requiresAuth: true, roles: ['admin'] }
            },
            {
                path: '/admin/users/create',
                component: AdminUsersAdd,
                meta: { requiresAuth: true, roles: ['admin'] }
            },
            {
                path: '/admin/evaluators/create',
                component: AdminEvaluatorsAdd,
                meta: { requiresAuth: true, roles: ['admin'] }
            },
            {
                path: '/admin/users/edit/:id',
                component: AdminUsersEdit,
                meta: { requiresAuth: true, roles: ['admin'] }
            },
            {
                path: '/admin/pawnshops/edit/:id',
                component: AdminPawnshopsEdit,
                meta: { requiresAuth: true, roles: ['admin'] }
            },
            {
                path: '/admin/categories/edit/:id',
                component: AdminCategoriesEdit,
                meta: { requiresAuth: true, roles: ['admin'] }
            },
            {
                path: '/admin/evaluators/edit/:id',
                component: AdminEvaluatorsEdit,
                meta: { requiresAuth: true, roles: ['admin'] }
            },
            {
                path: '/admin/pawnshops/create',
                component: AdminPawnshopsAdd,
                meta: { requiresAuth: true, roles: ['admin'] }
            },
            {
                path: '/admin/categories/create',
                component: AdminCategoriesAdd,
                meta: { requiresAuth: true, roles: ['admin'] }
            },
            {
                path: '/admin/pawnshops/cashbox/:id',
                component: AdminPawnshopsCashbox,
                meta: { requiresAuth: true, roles: ['admin'] }
            },

        ]
    },
    {
        path: '/login',
        component: Login,
        meta: { requiresAuth: false }
    },

]
const hasPermission = (route) => {
    return !route.meta?.roles || (route.meta?.roles && route.meta.roles.some((role) => role === store.state.user.role))
}
const configPaths = [
    '/config',
    '/set-bank-cashbox'
]
const goNext = (route) => {
    const user = store.state.user
    if(user.role === 'admin'){
        return false
    }
    const conf = user.config
    if(!conf.cashboxes_calculated){
        return '/config'
    }
    if(!conf.online_cashbox_set){
        return '/set-bank-cashbox'
    }
    if(!conf.orders_set){
        return '/set-orders'
    }
    if(configPaths.includes(route.path)){
        return '/'
    }
    return false
}

const router = VueRouter.createRouter({
    // 4. Provide the history implementation to use. We are using the hash history for simplicity here.
    history: VueRouter.createWebHistory(),
    routes, // short for `routes: routes`
})
router.beforeEach((to, from, next) => {
    if(store.getters.isAuthenticated){
        const jwtPayload = JSON.parse(window.atob(store.getters.getToken.split('.')[1]))
        if(!jwtPayload.exp){
            store.dispatch('logout').then(() => {
                next('/login')
            });
        }
    }
    if(to.path === '/login' && store.getters.isAuthenticated){
        next('/')
    }
    else if (to.matched.some((record) => record.meta.requiresAuth)) {
        if (!store.getters.isAuthenticated) {
            next('/login'); // Redirect to the login page if not authenticated
        } else if(!store.state.user){
            axios.get('/api/auth/get-user',{
                headers: {
                    'Authorization': 'Bearer ' + store.getters.getToken
                }
            }).then((res) => {
                if(!res.data.user){
                    store.dispatch('logout').then(() => {
                        next('/login')
                    });
                }else{
                    store.commit('setUser', res.data.user);
                    if(hasPermission(to)){
                        const nextRoute = goNext(to)
                        if(nextRoute){
                            if(nextRoute === to.path){
                                next()
                            }else{
                                next(nextRoute)
                            }

                        }else{
                            next()
                        }
                    }else{
                        next('/')
                    }
                }

            }).catch(() => {
                store.dispatch('logout');
                next('/login')
            })
        }else{
            if(hasPermission(to)){
                const nextRoute = goNext(to)
                if(nextRoute){
                    if(nextRoute === to.path){
                        next()
                    }else{
                        next(nextRoute)
                    }

                }else{
                    next()
                }
            }else{
                next('/')
            }

        }
    } else {
        next();
    }
});

export default router
