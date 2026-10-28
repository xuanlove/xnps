import { createRouter, createWebHistory } from 'vue-router';
import { getToken } from './api';
import AppShell from './components/AppShell.vue';
import Login from './pages/Login.vue';
import Dashboard from './pages/Dashboard.vue';
import Servers from './pages/Servers.vue';
import Clients from './pages/Clients.vue';
import Tunnels from './pages/Tunnels.vue';
import Hosts from './pages/Hosts.vue';
import Approvals from './pages/Approvals.vue';
import Billing from './pages/Billing.vue';
import Certificates from './pages/Certificates.vue';
import Upgrades from './pages/Upgrades.vue';
import Alerts from './pages/Alerts.vue';

const router = createRouter({
    history: createWebHistory('/admin'),
    routes: [
        { path: '/login', component: Login, meta: { public: true } },
        {
            path: '/',
            component: AppShell,
            children: [
                { path: '', name: 'dashboard', component: Dashboard },
                { path: 'servers', name: 'servers', component: Servers },
                { path: 'clients', name: 'clients', component: Clients },
                { path: 'tunnels', name: 'tunnels', component: Tunnels },
                { path: 'hosts', name: 'hosts', component: Hosts },
                { path: 'approvals', name: 'approvals', component: Approvals },
                { path: 'billing', name: 'billing', component: Billing },
                { path: 'certificates', name: 'certificates', component: Certificates },
                { path: 'upgrades', name: 'upgrades', component: Upgrades },
                { path: 'alerts', name: 'alerts', component: Alerts },
            ],
        },
        { path: '/:pathMatch(.*)*', redirect: '/' },
    ],
});

router.beforeEach((to) => {
    if (!to.meta.public && !getToken()) {
        return { path: '/login' };
    }
    if (to.path === '/login' && getToken()) {
        return { path: '/' };
    }
    return true;
});

export default router;
