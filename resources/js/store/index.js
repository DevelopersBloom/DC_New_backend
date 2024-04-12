import Vuex from 'vuex';
const store = new Vuex.Store({
    state: {
        user: null,
    },
    mutations: {
        setToken(state, token) {
            localStorage.setItem('token',token)
        },
        setUser(state, user) {
            state.user = user;
        },
        logout(state) {
            localStorage.removeItem('token')
            state.user = null;
        },
    },
    actions: {
        login({ commit }, { token, user }) {
            commit('setToken', token);
            commit('setUser', user);
        },
        logout({ commit }) {
            commit('logout');

        },
    },
    getters: {
        isAuthenticated: (state) => localStorage.getItem('token') !== null,
        getToken: (state) => localStorage.getItem('token'),
        getUser: (state) => state.user
    },
});

export default store;
