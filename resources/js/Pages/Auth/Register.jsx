import { useForm, usePage } from '@inertiajs/react';

export default function Register() {
    const { logo } = usePage().props;
    const form = useForm({ name: '', email: '', password: '', password_confirmation: '', is_gm: false });
    return (
        <div className="grid min-h-screen place-items-center bg-ink px-6">
            <form onSubmit={(e) => { e.preventDefault(); form.post('/register'); }} className="w-full max-w-md border border-line bg-panel p-8">
                {logo && <img src={logo} alt="Eldritch Runes" className="mb-8 w-full bg-transparent" />}
                <h1 className="font-display text-4xl">Take a name</h1>
                {['name', 'email', 'password', 'password_confirmation'].map((field) => (
                    <label key={field} className="mt-4 block text-sm capitalize">{field.replace('_', ' ')}
                        <input type={field.includes('password') ? 'password' : 'text'} className="mt-1 w-full border border-line bg-ink px-3 py-2" value={form.data[field]} onChange={(e) => form.setData(field, e.target.value)} />
                        {form.errors[field] && <span className="text-maroon">{form.errors[field]}</span>}
                    </label>
                ))}
                <label className="mt-4 flex items-center gap-2 text-sm text-silver">
                    <input type="checkbox" checked={form.data.is_gm} onChange={(e) => form.setData('is_gm', e.target.checked)} />
                    I am a game master
                </label>
                <button className="mt-6 w-full border border-maroon bg-maroon-deep py-2">Create account</button>
            </form>
        </div>
    );
}
