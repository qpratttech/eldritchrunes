import { useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { usePasskeyRegister } from '@laravel/passkeys/react';
import Shell, { buttonClass, inputClass, quietClass } from '../../Components/Shell';

export default function Show({ twoFactor, passkeys = [], description = '' }) {
    const player = usePage().props.auth.user;
    const removal = useForm({ password: '' });
    const form = useForm({
        email: player?.email || '',
        description: description || '',
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    function save(event) {
        event.preventDefault();
        form.patch('/player', {
            preserveScroll: true,
            onSuccess: () => form.reset('current_password', 'password', 'password_confirmation'),
        });
    }

    return (
        <Shell title="Player">
            <form onSubmit={save} className="max-w-lg space-y-4 border border-line bg-panel p-6">
                <div>
                    <p className="font-display text-3xl">{player?.name}</p>
                    <p className="mt-1 text-[11px] tracking-widest text-maroon uppercase">{player?.is_gm ? 'Game master' : 'Player'}</p>
                    <Link href={`/players/${player?.id}`} className="mt-2 inline-block text-sm text-silver underline">Public page</Link>
                </div>
                {player?.is_gm ? (
                    <div className="flex flex-wrap items-center justify-between gap-3 border border-line px-3 py-3">
                        <p className="text-sm text-silver">Step down to play without game master tools. Campaigns you already keep stay yours.</p>
                        <button type="button" className={quietClass} onClick={() => {
                            if (!window.confirm('Leave the game master role?')) return;
                            router.delete('/player/role', { preserveScroll: true });
                        }}>Become a player</button>
                    </div>
                ) : (
                    <div className="flex flex-wrap items-center justify-between gap-3 border border-line px-3 py-3">
                        <p className="text-sm text-silver">Take on the game master tools, including new campaigns and non-player characters.</p>
                        <button type="button" className={buttonClass} onClick={() => {
                            if (!window.confirm('Become a game master?')) return;
                            router.post('/player/role', {}, { preserveScroll: true });
                        }}>Become a game master</button>
                    </div>
                )}
                <label className="block text-xs tracking-widest text-silver uppercase">Description
                    <textarea className={`${inputClass} mt-1 normal-case`} rows={4} maxLength={500} value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} />
                </label>
                <p className="text-sm text-silver">A short note on your public page. {form.data.description.length}/500</p>
                {form.errors.description && <p className="text-sm text-maroon">{form.errors.description}</p>}
                <label className="block text-xs tracking-widest text-silver uppercase">Email
                    <input className={`${inputClass} mt-1 normal-case`} type="email" autoComplete="email" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} />
                </label>
                {form.errors.email && <p className="text-sm text-maroon">{form.errors.email}</p>}
                <label className="block text-xs tracking-widest text-silver uppercase">Current password
                    <input className={`${inputClass} mt-1`} type="password" autoComplete="current-password" value={form.data.current_password} onChange={(event) => form.setData('current_password', event.target.value)} />
                </label>
                {form.errors.current_password && <p className="text-sm text-maroon">{form.errors.current_password}</p>}
                <label className="block text-xs tracking-widest text-silver uppercase">New password
                    <input className={`${inputClass} mt-1`} type="password" autoComplete="new-password" value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} />
                </label>
                {form.errors.password && <p className="text-sm text-maroon">{form.errors.password}</p>}
                <label className="block text-xs tracking-widest text-silver uppercase">Confirm password
                    <input className={`${inputClass} mt-1`} type="password" autoComplete="new-password" value={form.data.password_confirmation} onChange={(event) => form.setData('password_confirmation', event.target.value)} />
                </label>
                <p className="text-sm text-silver">Leave the password fields blank to keep the current password.</p>
                <button className={buttonClass} disabled={form.processing}>Save</button>
            </form>
            <TwoFactor twoFactor={twoFactor} />
            <Passkeys passkeys={passkeys} />
            <form onSubmit={(event) => {
                event.preventDefault();
                if (!window.confirm('Delete your account? This cannot be undone.')) return;
                removal.delete('/player');
            }} className="mt-6 max-w-lg space-y-4 border border-maroon bg-panel p-6">
                <div>
                    <h2 className="font-display text-3xl">Delete account</h2>
                    <p className="mt-2 text-sm text-silver">Your characters and campaigns will be deleted along with your account.</p>
                </div>
                <label className="block text-xs tracking-widest text-silver uppercase">Current password
                    <input className={`${inputClass} mt-1`} type="password" autoComplete="current-password" value={removal.data.password} onChange={(event) => removal.setData('password', event.target.value)} />
                </label>
                {removal.errors.password && <p className="text-sm text-maroon">{removal.errors.password}</p>}
                <button className={buttonClass} disabled={removal.processing || removal.data.password === ''}>Delete account</button>
            </form>
        </Shell>
    );
}

function TwoFactor({ twoFactor }) {
    const code = useForm({ code: '' });

    return (
        <section className="mt-6 max-w-lg space-y-4 border border-line bg-panel p-6">
            <div>
                <h2 className="font-display text-3xl">Two-factor authentication</h2>
                <p className="mt-2 text-sm text-silver">An authenticator app adds a six-digit code when you sign in.</p>
            </div>
            {twoFactor?.confirming && (
                <div className="space-y-4">
                    <div className="inline-block bg-white p-3" dangerouslySetInnerHTML={{ __html: twoFactor.qr }} />
                    <p className="text-sm text-silver">Or enter this key manually: <span className="font-mono text-parchment">{twoFactor.secret}</span></p>
                    <form onSubmit={(event) => { event.preventDefault(); code.post('/user/confirmed-two-factor-authentication', { preserveScroll: true, errorBag: 'confirmTwoFactorAuthentication', onSuccess: () => code.reset() }); }} className="space-y-3">
                        <label className="block text-xs tracking-widest text-silver uppercase">Authentication code
                            <input className={`${inputClass} mt-1 tracking-[0.3em]`} inputMode="numeric" autoComplete="one-time-code" value={code.data.code} onChange={(event) => code.setData('code', event.target.value)} />
                        </label>
                        {code.errors.code && <p className="text-sm text-maroon">{code.errors.code}</p>}
                        <button className={buttonClass} disabled={code.processing}>Confirm</button>
                    </form>
                </div>
            )}
            {twoFactor?.enabled && (
                <div className="space-y-4">
                    <p className="text-sm text-parchment">Authenticator app is on.</p>
                    <div>
                        <p className="text-xs tracking-widest text-silver uppercase">Recovery codes</p>
                        <ul className="mt-2 grid grid-cols-2 gap-2 font-mono text-sm">
                            {twoFactor.recoveryCodes.map((recovery) => <li key={recovery}>{recovery}</li>)}
                        </ul>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button type="button" className={quietClass} onClick={() => router.post('/user/two-factor-recovery-codes', {}, { preserveScroll: true })}>New recovery codes</button>
                        <button type="button" className={quietClass} onClick={() => { if (window.confirm('Turn off two-factor authentication?')) router.delete('/user/two-factor-authentication', { preserveScroll: true }); }}>Turn off</button>
                    </div>
                </div>
            )}
            {!twoFactor?.enabled && !twoFactor?.confirming && (
                <button type="button" className={buttonClass} onClick={() => router.post('/user/two-factor-authentication', {}, { preserveScroll: true })}>Turn on</button>
            )}
        </section>
    );
}

function Passkeys({ passkeys }) {
    const [name, setName] = useState('This device');
    const passkey = usePasskeyRegister({
        onSuccess: () => router.reload({ preserveScroll: true }),
    });

    return (
        <section className="mt-6 max-w-lg space-y-4 border border-line bg-panel p-6">
            <div>
                <h2 className="font-display text-3xl">Passkeys</h2>
                <p className="mt-2 text-sm text-silver">A passkey signs you in with this device, without a password.</p>
            </div>
            {passkeys.length === 0 && <p className="text-sm text-silver">No passkeys yet.</p>}
            {passkeys.map((row) => (
                <div key={row.id} className="flex items-center justify-between gap-3 text-sm">
                    <div>
                        <div>{row.name}</div>
                        <div className="text-xs text-silver">{row.last_used ? `Used ${row.last_used}` : 'Not used yet'}</div>
                    </div>
                    <button type="button" className="text-xs text-silver underline" onClick={() => router.delete(`/user/passkeys/${row.id}`, { preserveScroll: true })}>Remove</button>
                </div>
            ))}
            {passkey.isSupported ? (
                <form onSubmit={(event) => { event.preventDefault(); if (name.trim()) passkey.register(name.trim()); }} className="flex flex-wrap items-end gap-2">
                    <label className="min-w-0 flex-1 text-xs tracking-widest text-silver uppercase">Name
                        <input className={`${inputClass} mt-1 normal-case`} value={name} onChange={(event) => setName(event.target.value)} />
                    </label>
                    <button className={buttonClass} disabled={passkey.isLoading || !name.trim()}>{passkey.isLoading ? 'Waiting…' : 'Add passkey'}</button>
                </form>
            ) : (
                <p className="text-sm text-silver">This browser cannot create a passkey here. Use localhost or a secure address.</p>
            )}
            {passkey.error && <p className="text-sm text-maroon">{passkey.error}</p>}
        </section>
    );
}
