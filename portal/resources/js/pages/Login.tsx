import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../lib/auth';
import { errMsg } from '../lib/api';
import { Button, Card, Flash, Input } from '../components/ui';

export default function Login() {
    const { login, user } = useAuth();
    const navigate = useNavigate();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    if (user) {
        navigate('/', { replace: true });
        return null;
    }

    const submit = async (e: React.FormEvent) => {
        e.preventDefault();
        setLoading(true);
        setError(null);
        try {
            await login(email, password);
            navigate('/', { replace: true });
        } catch (err) {
            setError(errMsg(err));
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-gray-100 p-4">
            <div className="w-full max-w-sm">
                <div className="mb-6 text-center">
                    <div className="text-2xl font-bold text-indigo-700">StockPilot</div>
                    <p className="text-sm text-gray-500">Sign in to your workspace</p>
                </div>
                <Card>
                    <form onSubmit={submit} className="space-y-3">
                        <Flash message={error} tone="red" />
                        <Input label="Email" type="email" required value={email} onChange={(e) => setEmail(e.target.value)} />
                        <Input label="Password" type="password" required value={password} onChange={(e) => setPassword(e.target.value)} />
                        <Button type="submit" className="w-full" loading={loading}>
                            Sign in
                        </Button>
                    </form>
                </Card>
            </div>
        </div>
    );
}
