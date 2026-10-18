import { useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';

export default function TwoFactorChallenge() {
    const { logo } = usePage().props;
    const [recovery, setRecovery] = useState(false);
    const form = useForm({ code: '', recovery_code: '' });

    function submit(event) {
        event.preventDefault();
        form.post('/two-factor-challenge', {
            onSuccess: () => form.reset(),
        });
    }

    return (
        <div className="grid min-h-screen place-items-center bg-ink px-6">
            <form onSubmit={submit} className="w-full max-w-md border border-line bg-panel p-8">
                {logo ? <img src={logo} alt="Eldritch Runes" className="mb-8 w-full bg-transparent" /> : <div className="mb-8 grid h-16 w-16 place-items-center border border-line font-display text-2xl">ER</div>}
                <h1 className="font-display text-4xl">Second watch</h1>
                <p className="mt-2 text-sm text-silver">{recovery ? 'Enter one of your recovery codes.' : 'Enter the six-digit code from your authenticator app.'}</p>
                {recovery ? (
                    <label className="mt-6 block text-sm">Recovery code
                        <input className="mt-1 w-full border border-line bg-ink px-3 py-2" autoComplete="one-time-code" value={form.data.recovery_code} onChange={(event) => form.setData('recovery_code', event.target.value)} />
                    </label>
                ) : (
                    <label className="mt-6 block text-sm">Authentication code
                        <input className="mt-1 w-full border border-line bg-ink px-3 py-2 tracking-[0.3em]" inputMode="numeric" autoComplete="one-time-code" value={form.data.code} onChange={(event) => form.setData('code', event.target.value)} />
                    </label>
                )}
                {form.errors.code && <p className="mt-2 text-sm text-maroon">{form.errors.code}</p>}
                {form.errors.recovery_code && <p className="mt-2 text-sm text-maroon">{form.errors.recovery_code}</p>}
                <button className="mt-6 w-full border border-maroon bg-maroon-deep py-2 text-parchment" disabled={form.processing}>Sign in</button>
                <button type="button" className="mt-4 block w-full text-center text-sm text-silver" onClick={() => { setRecovery((current) => !current); form.clearErrors(); }}>
                    {recovery ? 'Use an authenticator code' : 'Use a recovery code'}
                </button>
            </form>
        </div>
    );
}
