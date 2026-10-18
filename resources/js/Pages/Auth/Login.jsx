import { useForm, usePage } from '@inertiajs/react';
import { usePasskeyVerify } from '@laravel/passkeys/react';

export default function Login() {
    const { logo } = usePage().props;
    const form = useForm({ email: '', password: '', remember: false });
    const passkey = usePasskeyVerify({
        onSuccess: (response) => {
            window.location.assign(response.redirect || '/dashboard');
        },
    });
    return (
        <div className="grid min-h-screen place-items-center bg-ink px-6">
            <form onSubmit={(e) => { e.preventDefault(); form.post('/login'); }} className="w-full max-w-md border border-line bg-panel p-8">
                {logo ? <img src={logo} alt="Eldritch Runes" className="mb-8 w-full bg-transparent" /> : <div className="mb-8 grid h-16 w-16 place-items-center border border-line font-display text-2xl">ER</div>}
                <h1 className="font-display text-4xl">Enter the dark</h1>
                <p className="mt-2 text-sm text-silver">The watchfires are still burning.</p>
                <label className="mt-6 block text-sm">Email
                    <input className="mt-1 w-full border border-line bg-ink px-3 py-2" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                </label>
                {form.errors.email && <p className="mt-1 text-sm text-maroon">{form.errors.email}</p>}
                <label className="mt-4 block text-sm">Password
                    <input type="password" className="mt-1 w-full border border-line bg-ink px-3 py-2" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                </label>
                <button className="mt-6 w-full border border-maroon bg-maroon-deep py-2 text-parchment" disabled={form.processing}>Sign in</button>
                {passkey.isSupported && (
                    <button type="button" className="mt-3 w-full border border-line py-2 text-sm text-silver hover:border-silver hover:text-parchment" disabled={passkey.isLoading} onClick={passkey.verify}>
                        {passkey.isLoading ? 'Waiting for the passkey…' : 'Sign in with a passkey'}
                    </button>
                )}
                {passkey.error && <p className="mt-2 text-sm text-maroon">{passkey.error}</p>}
                <a href="/register" className="mt-4 block text-center text-sm text-silver">Create an account</a>
            </form>
        </div>
    );
}
