import React, { useState } from 'react';
import { apiPost, errMsg } from '../lib/api';
import { Button, Card, Flash, Input } from '../components/ui';

export default function Setup({ onDone }: { onDone: () => void }) {
    const [step, setStep] = useState(1);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [db, setDb] = useState({ host: '127.0.0.1', port: '3306', database: '', username: '', password: '' });
    const [admin, setAdmin] = useState({
        organization_name: '',
        name: '',
        email: '',
        password: '',
        currency: 'BDT',
    });

    const connect = async () => {
        setLoading(true);
        setError(null);
        try {
            await apiPost('/setup/database', db);
            setStep(2);
        } catch (e) {
            setError(errMsg(e));
        } finally {
            setLoading(false);
        }
    };

    const finish = async () => {
        setLoading(true);
        setError(null);
        try {
            await apiPost('/setup/admin', admin);
            onDone();
        } catch (e) {
            setError(errMsg(e));
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-gray-100 p-4">
            <div className="w-full max-w-md">
                <div className="mb-6 text-center">
                    <div className="text-2xl font-bold text-indigo-700">StockPilot</div>
                    <p className="text-sm text-gray-500">Inventory &amp; warehouse management</p>
                </div>

                <Card>
                    <div className="mb-4 flex gap-1 text-xs">
                        <span className={step === 1 ? 'font-semibold text-indigo-600' : 'text-gray-400'}>1 · Database</span>
                        <span className="text-gray-300">→</span>
                        <span className={step === 2 ? 'font-semibold text-indigo-600' : 'text-gray-400'}>2 · Admin account</span>
                    </div>

                    <Flash message={error} tone="red" />

                    {step === 1 && (
                        <div className="space-y-3">
                            <p className="text-sm text-gray-600">
                                StockPilot creates all of its own tables. Enter your MySQL / MariaDB credentials.
                            </p>
                            <Input label="Database host" value={db.host} onChange={(e) => setDb({ ...db, host: e.target.value })} />
                            <Input label="Port" value={db.port} onChange={(e) => setDb({ ...db, port: e.target.value })} />
                            <Input label="Database name" placeholder="stockpilot" value={db.database} onChange={(e) => setDb({ ...db, database: e.target.value })} />
                            <Input label="Username" value={db.username} onChange={(e) => setDb({ ...db, username: e.target.value })} />
                            <Input label="Password" type="password" value={db.password} onChange={(e) => setDb({ ...db, password: e.target.value })} />
                            <Button className="w-full" loading={loading} onClick={connect}>
                                Connect &amp; create tables
                            </Button>
                        </div>
                    )}

                    {step === 2 && (
                        <div className="space-y-3">
                            <p className="text-sm text-gray-600">Create the first administrator account.</p>
                            <Input label="Company / organization name" value={admin.organization_name} onChange={(e) => setAdmin({ ...admin, organization_name: e.target.value })} />
                            <Input label="Currency" maxLength={3} value={admin.currency} onChange={(e) => setAdmin({ ...admin, currency: e.target.value.toUpperCase() })} />
                            <Input label="Your name" value={admin.name} onChange={(e) => setAdmin({ ...admin, name: e.target.value })} />
                            <Input label="Email" type="email" value={admin.email} onChange={(e) => setAdmin({ ...admin, email: e.target.value })} />
                            <Input label="Password" type="password" value={admin.password} onChange={(e) => setAdmin({ ...admin, password: e.target.value })} />
                            <Button className="w-full" loading={loading} onClick={finish}>
                                Finish setup
                            </Button>
                        </div>
                    )}
                </Card>
            </div>
        </div>
    );
}
