import React, { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import './bootstrap';
import { AuthProvider, useAuth } from './lib/auth';
import { apiGet } from './lib/api';
import Layout from './components/Layout';
import { FullPageSpinner } from './components/ui';
import Setup from './pages/Setup';
import Login from './pages/Login';
import Dashboard from './pages/Dashboard';
import Items from './pages/Items';
import Products from './pages/Products';
import Orders from './pages/Orders';
import Activity from './pages/Activity';
import Reports from './pages/Reports';
import Stores from './pages/Stores';
import Settings from './pages/Settings';
import Users from './pages/Users';

const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: 1, refetchOnWindowFocus: false } },
});

function Protected() {
    const { user, loading } = useAuth();
    if (loading) return <FullPageSpinner />;
    if (!user) return <Navigate to="/login" replace />;
    return <Layout />;
}

function AppRoutes() {
    return (
        <Routes>
            <Route path="/login" element={<Login />} />
            <Route element={<Protected />}>
                <Route path="/" element={<Dashboard />} />
                <Route path="/items" element={<Items />} />
                <Route path="/products" element={<Products />} />
                <Route path="/orders" element={<Orders />} />
                <Route path="/activity" element={<Activity />} />
                <Route path="/reports" element={<Reports />} />
                <Route path="/stores" element={<Stores />} />
                <Route path="/settings" element={<Settings />} />
                <Route path="/users" element={<Users />} />
            </Route>
            <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
    );
}

function SetupGate({ children }: { children: React.ReactNode }) {
    const [setup, setSetup] = useState<boolean | null>(null);

    useEffect(() => {
        apiGet<{ setup: boolean }>('/setup/status')
            .then((r) => setSetup(r.setup))
            .catch(() => setSetup(true));
    }, []);

    if (setup === null) return <FullPageSpinner />;
    if (setup === false) return <Setup onDone={() => setSetup(true)} />;
    return <>{children}</>;
}

export default function App() {
    return (
        <QueryClientProvider client={queryClient}>
            <BrowserRouter>
                <AuthProvider>
                    <SetupGate>
                        <AppRoutes />
                    </SetupGate>
                </AuthProvider>
            </BrowserRouter>
        </QueryClientProvider>
    );
}

const el = document.getElementById('app');
if (el) {
    createRoot(el).render(<App />);
}
