import { createRouter, createWebHistory } from "vue-router";
import LoginPage from "./components/layouts/login/login.page.vue";

import DashboardLayout from "./components/layouts/dashboard/dashboard.responsiveLayout.vue";
import DashboardHome from "./components/layouts/dashboard/dashboard.home.vue";

const router = createRouter({
    history: createWebHistory(),
    routes: [
        {path: "/", name: "Login", component: LoginPage},
        {
            path: '/dashboard', name: 'Dashboard Layout', component: DashboardLayout,
            children: [
                {
                    path: 'dashboard-home', name: 'Dashboard Home', component: DashboardHome
                }
            ]
        }
    ]
});

export default router;
