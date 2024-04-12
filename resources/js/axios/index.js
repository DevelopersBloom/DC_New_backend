import axios from "axios";
import router from "../router"
import store from "../store";
const getAxios = () => {
    const axiosInstance = axios.create();
    axiosInstance.defaults.headers.common['Authorization'] = `Bearer ${localStorage.getItem('token')}`;

// Response interceptor to handle errors
    axiosInstance.interceptors.response.use(
        function(response) {
            // Do something with successful response
            return response;
        },
        function(error) {
            // Do something with response error
            // For example, you can handle 401 Unauthorized or 403 Forbidden errors here
            // You can also redirect to a login page or display a notification to the user
            if (error.response.status === 401) {
                store.dispatch('logout');
                router.push('/login');
            } else {
                // Display an error message to the user
                console.error('Request error:', error.message);
            }
            return Promise.reject(error);
        }
    );

    return axiosInstance
}
export default getAxios;
