import axios from 'axios'

/**
 * Instancia de axios configurada para el CRM.
 *
 * - baseURL apunta a /api — todas las llamadas son relativas al mismo dominio,
 *   sin CORS. En Docker el backend y el frontend comparten el mismo origen.
 * - withCredentials: true — envía las cookies de sesión Laravel en cada request.
 *   Sin esto, auth:sanctum stateful no puede leer la sesión.
 * - withXSRFToken: true — axios lee el cookie XSRF-TOKEN que Laravel setea y lo
 *   reenvía como header X-XSRF-TOKEN. Sin esto todos los POST/PUT/DELETE
 *   devuelven 419 CSRF token mismatch.
 * - Accept: application/json — le dice a Laravel que responda JSON (no Blade).
 *   Importante: sin este header, los 401/403/422 vienen como redirects HTML
 *   en lugar de respuestas JSON que el Vue pueda manejar.
 */
const api = axios.create({
    baseURL: '/api',
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
})

// Interceptor de respuesta: si Laravel devuelve 401 (sesión expirada),
// redirige al login de Blade en lugar de dejar al Vue en estado roto.
api.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response?.status === 401) {
            window.location.href = '/login'
        }
        return Promise.reject(error)
    },
)

export default api
