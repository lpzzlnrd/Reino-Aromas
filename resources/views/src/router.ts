import { createRouter, createWebHistory } from "vue-router";

import DashboardLayout          from "./components/layouts/dashboard/dashboard.responsiveLayout.vue";
import DashboardHome            from "./components/layouts/dashboard/dashboard.home.vue";
import MessagesLayout           from "./components/layouts/messages/messages.responsiveLayout.vue";
import MessagesHome             from "./components/layouts/messages/messages.home.vue";
import SettingsResponsiveLayout from "./components/layouts/settings/settings.responsiveLayout.vue";
import SettingsAccounts         from "./components/layouts/settings/settings.accounts.vue";
import SettingsIgAutomations    from "./components/layouts/settings/settings.instagramAutomations.vue";
import TicketsBoard             from "./components/layouts/tickets/tickets.board.vue";
import UsersHome                from "./components/layouts/users/users.home.vue";
import TemplatesHome            from "./components/layouts/templates/templates.home.vue";
import ClientsHome              from "./components/layouts/clients/clients.home.vue";
import ReportsHome              from "./components/layouts/reports/reports.home.vue";

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
                    // El tablero del embudo. Estuvo bajo settings/status hasta
                    // Semana 4: es operación diaria, no configuración, y va al
                    // lado de messages y clients.
                    path: "tickets",
                    name: "Tickets Board",
                    component: TicketsBoard,
                    meta: { title: "Tablero" },
                },
                {
                    path: "reports",
                    name: "Reports",
                    component: ReportsHome,
                    meta: { title: "Reportes" },
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
                            // El tablero se mudó a /app/tickets. Se deja el
                            // redirect porque esta URL puede estar guardada en
                            // el navegador de alguien.
                            path: "status",
                            redirect: { name: "Tickets Board" },
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
                        {
                            // Instagram no tiene WhatsApp Flows: Ice Breakers y
                            // Persistent Menu son lo mas cercano. Va bajo
                            // settings porque es configuracion, no operacion
                            // diaria como messages o tickets.
                            path: "instagram-automations",
                            name: "Instagram Automations",
                            component: SettingsIgAutomations,
                            meta: { title: "Automatizaciones IG" },
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
