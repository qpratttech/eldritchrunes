import { Link, usePage } from '@inertiajs/react';
import { buttonClass, quietClass } from '../Components/Shell';

const points = [
    ['Character Forge', 'Build an adventurer from species, background, class, and specialization, then record talents, tactics, spells, and gear.'],
    ['The table', 'Keep a campaign map with tokens, fog, and a combat log. Players join with a character. The game master runs the company.'],
    ['The roll', 'Percentile checks, wounds, and actions are settled in play, with the core rules beside the table.'],
];

export default function Landing() {
    const crest = usePage().props.crest;

    return (
        <div className="min-h-screen bg-ink text-parchment">
            <header className="flex items-center justify-between px-6 py-5 md:px-12">
                <p className="text-[11px] tracking-[0.32em] text-silver uppercase">Eldritch Runes</p>
                <div className="flex items-center gap-5">
                    <span className="border border-maroon/70 px-2 py-1 text-[10px] tracking-[0.22em] text-maroon uppercase">Beta</span>
                    <Link href="/login" className="text-sm text-silver hover:text-parchment">Sign in</Link>
                </div>
            </header>
            <main className="mx-auto grid max-w-6xl items-center gap-10 px-6 pb-20 md:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)] md:gap-16 md:px-12 md:pb-28">
                <img src={crest} alt="Eldritch Runes, 2nd Edition" className="mx-auto w-full max-w-md bg-transparent" />
                <div className="max-w-xl">
                    <p className="text-xs tracking-[0.28em] text-maroon uppercase">Second edition</p>
                    <h1 className="mt-3 font-display text-5xl leading-[0.95] md:text-6xl">A table for the dark beyond the watchfires</h1>
                    <p className="mt-6 text-sm leading-7 text-silver">Eldritch Runes is a percentile game of steel, nerve, and haunted wilderness. This app is the virtual table and Character Forge for the second edition: one place to make an adventurer and play them at the table.</p>
                    <ul className="mt-10 space-y-6 border-t border-line pt-8">
                        {points.map(([title, text]) => (
                            <li key={title}>
                                <h2 className="font-display text-2xl">{title}</h2>
                                <p className="mt-1 text-sm leading-6 text-parchment-dim">{text}</p>
                            </li>
                        ))}
                    </ul>
                    <div className="mt-10 border border-line bg-panel px-5 py-4">
                        <p className="text-xs tracking-[0.22em] text-maroon uppercase">Beta testing</p>
                        <p className="mt-2 text-sm leading-6 text-parchment-dim">This app is in beta. Access is limited to beta testers. A sign-in is required to enter the table.</p>
                    </div>
                    <div className="mt-6 flex flex-wrap gap-3">
                        <Link href="/login" className={buttonClass}>Sign in</Link>
                        <Link href="/register" className={quietClass}>Beta account</Link>
                    </div>
                </div>
            </main>
        </div>
    );
}
