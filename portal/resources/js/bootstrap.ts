import axios from 'axios';

// Same-origin SPA → cookie sessions "just work" with Laravel Sanctum.
axios.defaults.withCredentials = true;
axios.defaults.withXSRFToken = true;
axios.defaults.baseURL = '/';

export async function getCsrfCookie(): Promise<void> {
    await axios.get('/sanctum/csrf-cookie');
}

export default axios;
