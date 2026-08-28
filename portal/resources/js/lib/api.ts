import axios from '../bootstrap';

export type ApiError = { message: string; errors?: Record<string, string[]> };

const client = axios.create({ baseURL: '/api' });

export async function apiGet<T>(url: string, params?: Record<string, unknown>): Promise<T> {
    const { data } = await client.get<T>(url, { params });
    return data;
}

export async function apiPost<T>(url: string, body?: unknown): Promise<T> {
    const { data } = await client.post<T>(url, body);
    return data;
}

export async function apiPut<T>(url: string, body?: unknown): Promise<T> {
    const { data } = await client.put<T>(url, body);
    return data;
}

export async function apiPatch<T>(url: string, body?: unknown): Promise<T> {
    const { data } = await client.patch<T>(url, body);
    return data;
}

export async function apiDelete<T>(url: string): Promise<T> {
    const { data } = await client.delete<T>(url);
    return data;
}

/** Extract a readable message from an Axios error. */
export function errMsg(error: unknown): string {
    if (axios.isAxiosError(error)) {
        const payload = error.response?.data as ApiError | undefined;
        const first = payload?.errors ? Object.values(payload.errors)[0]?.[0] : undefined;
        return first ?? payload?.message ?? error.message;
    }
    return error instanceof Error ? error.message : 'Something went wrong';
}
