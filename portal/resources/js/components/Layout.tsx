import React from 'react';
import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import { useAuth } from '../lib/auth';
import { cn } from '../lib/cn';

const nav = [
    { to: '/', label: 'Dashboard', icon: '▦', end: true },
    { to: '/items', label: 'Items', icon: '📦' },
    { to: '/products', label: 'Products & Recipes', icon: '🧩' },
    { to: '/orders', label: 'Orders', icon: '🧾' },
    { to: '/activity', label: 'Activity', icon: '📜' },
    { to: '/reports', label: 'Reports', icon: '📊' },
    { to: '/stores', label: 'Stores', icon: '🔌' },
    { to: '/settings', label: 'Settings', icon: '⚙️' },
    { to: '/users', label: 'Team', icon: '👥', adminOnly: true },
];

export default function Layout() {
    const { user, logout } = useAuth();
    const navigate = useNavigate();

    const handleLogout = async () => {
        await logout();
        navigate('/login');
    };

    return (
        <div className="flex min-h-screen">
            <aside className="flex w-60 flex-col border-r border-gray-200 bg-white">
                <div className="border-b border-gray-100 px-5 py-4">
                    <div className="text-base font-bold text-indigo-700">StockPilot</div>
                    <div className="text-xs text-gray-400">{user?.organization?.name}</div>
                </div>
                <nav className="flex-1 space-y-0.5 p-3">
                    {nav
                        .filter((item) => !item.adminOnly || user?.role === 'admin')
                        .map((item) => (
                            <NavLink
                                key={item.to}
                                to={item.to}
                                end={item.end}
                                className={({ isActive }) =>
                                    cn(
                                        'flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition',
                                        isActive ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:bg-gray-50',
                                    )
                                }
                            >
                                <span className="text-base leading-none">{item.icon}</span>
                                {item.label}
                            </NavLink>
                        ))}
                </nav>
                <div className="border-t border-gray-100 p-4">
                    <div className="text-sm font-medium text-gray-700">{user?.name}</div>
                    <div className="text-xs capitalize text-gray-400">{user?.role}</div>
                    <button onClick={handleLogout} className="mt-2 text-xs font-medium text-red-500 hover:text-red-700">
                        Sign out
                    </button>
                </div>
            </aside>
            <main className="flex-1 overflow-y-auto bg-gray-50">
                <div className="mx-auto max-w-6xl p-6">
                    <Outlet />
                </div>
            </main>
        </div>
    );
}
