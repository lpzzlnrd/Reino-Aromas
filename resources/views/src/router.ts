import { createRouter, createWebHistory } from "vue-router";

import DashboardLayout          from "./components/layouts/dashboard/dashboard.responsiveLayout.vue";
import DashboardHome            from "./components/layouts/dashboard/dashboard.home.vue";
import MessagesLayout           from "./components/layouts/messages/messages.responsiveLayout.vue";
import MessagesHome             from "./components/layouts/messages/messages.home.vue";
import SettingsResponsiveLayout from "./components/layouts/settings/settings.responsiveLayout.vue";
import SettingsAccounts         from "./components/layouts/settings/settings.accounts.vue";
import UserStatus               from "./components/layouts/settings/settings.updateStatus.vue";
import UsersHome                from "./components/layouts/users/users.home.vue";
import TemplatesHome            from "./components/layouts/templates/templates.home.vue";
import ClientsHome              from "./components/layouts/clients/clients.home.vue";

/**
 * Rutas de la SPA Vue.
 *
 * El login fue removido de aquí: lo maneja Laravel con Blade + sesión propia.
 * Cuando Laravel autentica al usuario redirige a /app, donde Vue toma control.
 *
 * Protección: todas las rutas /app/* están cubiertas por el middleware 'auth'
 * en web.php — si el usuario no tiene sesión, Laravel lo manda al login
 * antes de que el HTML del Vue siquiera se cargue.
 */
const router = createRouter({
    history: createWebHistory(),
    routes: [
        {
            path: "/app",
            name: "Dashboard Layout",
            component: DashboardLayout,
            children: [
                {
                    // /app  →  dashboard por defecto
                    path: "",
                    name: "Dashboard Home",
                    component: DashboardHome,
                    meta: { title: "Panel de control" },
                },
                {
                    path: "messages",
                    name: "Dashboard Messages",
                    component: MessagesLayout,
                    children: [
                        {
                            path: "",
                            name: "Messages Home",
                            component: MessagesHome,
                            meta: { title: "Mensajes" },
                        },
                    ],
                },
                {
                    // Clientes va al mismo nivel que messages y no bajo
                    // settings: es operación diaria, no configuración.
                    path: "clients",
                    name: "Clients",
                    component: ClientsHome,
                    meta: { title: "Clientes" },
                },
                {
                    path: "settings",
                    name: "Dashboard Settings",
                    component: SettingsResponsiveLayout,
                    children: [
                        {
                            path: "accounts",
                            name: "Accounts",
                            component: SettingsAccounts,
                            meta: { title: "Cuentas" },
                        },
                        {
                            path: "status",
                            name: "Users status",
                            component: UserStatus,
                            meta: { title: "Estado de usuarios" },
                        },
                        {
                            path: "users",
                            name: "Users",
                            component: UsersHome,
                            meta: { title: "Administradores" },
                        },
                        {
                            path: "templates",
                            name: "Templates",
                            component: TemplatesHome,
                            meta: { title: "Plantillas" },
                        },
                    ],
                },
            ],
        },

        // Cualquier ruta no reconocida vuelve al dashboard
        { path: "/:pathMatch(.*)*", redirect: "/app" },
    ],
});

export default router;
