import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { apiDelete, apiGet, apiPatch, apiPost, errMsg } from '../lib/api';
import { Badge, Button, Flash, Input, Modal, PageHeader, Select, Table } from '../components/ui';

interface User {
    id: number;
    name: string;
    email: string;
    role: 'admin' | 'manager' | 'staff';
    is_active: boolean;
    last_login_at: string | null;
}

const roles = ['admin', 'manager', 'staff'];

const roleTone: Record<string, 'purple' | 'blue' | 'gray'> = { admin: 'purple', manager: 'blue', staff: 'gray' };

export default function Users() {
    const qc = useQueryClient();
    const [flash, setFlash] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [form, setForm] = useState({ name: '', email: '', password: '', role: 'staff' });

    const { data } = useQuery({ queryKey: ['users'], queryFn: () => apiGet<{ users: User[] }>('/users') });

    const create = useMutation({
        mutationFn: () => apiPost('/users', form),
        onSuccess: () => { qc.invalidateQueries({ queryKey: ['users'] }); setCreateOpen(false); setFlash('Team member added.'); setForm({ name: '', email: '', password: '', role: 'staff' }); },
        onError: (e) => setError(errMsg(e)),
    });

    const setRole = useMutation({
        mutationFn: ({ id, role }: { id: number; role: string }) => apiPatch(`/users/${id}/role`, { role }),
        onSuccess: () => qc.invalidateQueries({ queryKey: ['users'] }),
    });

    const toggle = useMutation({
        mutationFn: ({ id, active }: { id: number; active: boolean }) => apiPatch(`/users/${id}/${active ? 'deactivate' : 'activate'}`),
        onSuccess: () => qc.invalidateQueries({ queryKey: ['users'] }),
    });

    const remove = useMutation({
        mutationFn: (id: number) => apiDelete(`/users/${id}`),
        onSuccess: () => { qc.invalidateQueries({ queryKey: ['users'] }); setFlash('User removed.'); },
        onError: (e) => setFlash(errMsg(e)),
    });

    return (
        <div>
            <PageHeader title="Team" subtitle="Staff accounts with role-based permissions" actions={<Button onClick={() => setCreateOpen(true)}>+ Add member</Button>} />

            <Flash message={flash} />
            <Flash message={error} tone="red" />

            <Table head={['Name', 'Email', 'Role', 'Status', 'Last login', '']}>
                {data?.users.map((u) => (
                    <tr key={u.id} className="hover:bg-gray-50">
                        <td className="px-4 py-2 font-medium text-gray-800">{u.name}</td>
                        <td className="px-4 py-2 text-gray-600">{u.email}</td>
                        <td className="px-4 py-2">
                            <Select
                                value={u.role}
                                onChange={(e) => setRole.mutate({ id: u.id, role: e.target.value })}
                                className="max-w-[120px] py-1"
                            >
                                {roles.map((r) => <option key={r} value={r}>{r}</option>)}
                            </Select>
                        </td>
                        <td className="px-4 py-2">{u.is_active ? <Badge tone="green">active</Badge> : <Badge tone="red">inactive</Badge>}</td>
                        <td className="px-4 py-2 text-gray-500">{u.last_login_at ? new Date(u.last_login_at).toLocaleString() : '—'}</td>
                        <td className="px-4 py-2 text-right whitespace-nowrap">
                            <Button size="sm" variant="ghost" onClick={() => toggle.mutate({ id: u.id, active: u.is_active })}>
                                {u.is_active ? 'Deactivate' : 'Activate'}
                            </Button>
                            <Button size="sm" variant="ghost" onClick={() => { if (confirm(`Remove ${u.name}?`)) remove.mutate(u.id); }}>🗑</Button>
                        </td>
                    </tr>
                ))}
            </Table>

            <Modal open={createOpen} onClose={() => setCreateOpen(false)} title="Add team member">
                <div className="space-y-3">
                    <Input label="Name *" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                    <Input label="Email *" type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
                    <Input label="Password *" type="password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} />
                    <Select label="Role" value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })}>
                        {roles.map((r) => <option key={r} value={r}>{r}</option>)}
                    </Select>
                    <Button className="w-full" loading={create.isPending} onClick={() => create.mutate()}>Add</Button>
                </div>
            </Modal>
        </div>
    );
}
