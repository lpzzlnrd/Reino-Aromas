import { createRouter, createWebHistory } from "vue-router";
import LoginPage from "./components/layouts/login/login.page.vue";

import DashboardLayout from "./components/layouts/dashboard/dashboard.responsiveLayout.vue";
import DashboardHome from "./components/layouts/dashboard/dashboard.home.vue";

import MessagesLayout from "./components/layouts/messages/messages.responsiveLayout.vue";
import MessagesHome from "./components/layouts/messages/messages.home.vue"

import SettingsResponsiveLayout from "./components/layouts/settings/settings.responsiveLayout.vue";
import SettingsAccounts from "./components/layouts/settings/settings.accounts.vue";
import UserStatus from "./components/layouts/settings/settings.updateStatus.vue";

const router = createRouter({
    history: createWebHistory(),
    routes: [
        {path: "/", name: "Login", component: LoginPage},
        {
            path: '/dashboard', name: 'Dashboard Layout', component: DashboardLayout,
            children: [
                { path: 'home', name: 'Dashboard Home', component: DashboardHome, meta: { title: 'Panel de control' } },
                {
                    path: 'messages', name: 'Dashboard Messages', component: MessagesLayout,
                    children: [
                        { path: 'home', name: 'Messages Home', component: MessagesHome, meta: { title: 'Mensajes' } }
                    ]
                },
                {
                    path: 'settings', name: 'Dashboard Settings', component: SettingsResponsiveLayout,
                    children: [
                        { path: 'accounts', name: 'Accounts', component: SettingsAccounts, meta: { title: 'Cuentas' } },
                        { path: 'status', name: 'Users status', component: UserStatus, meta: { title: 'Users status' } }
                    ]
                }
            ]
        }
    ]
});

export default router;
