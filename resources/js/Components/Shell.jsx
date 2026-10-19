import { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';

export function Portrait({ src, name = '', size = 'md' }) {
    const box = size === '2xl' ? 'h-96 w-72' : size === 'xl' ? 'h-48 w-36' : size === 'lg' ? 'h-28 w-24' : 'h-24 w-20';
    if (! src) {
        return <Mark letter={(name || ' ').slice(0, 1)} size={size} />;
    }

    return <img src={src} alt="" className={`${box} shrink-0 bg-transparent object-contain object-bottom`} />;
}

export function Mark({ letter = 'ER', size = 'md' }) {
    const box = size === 'lg' ? 'h-16 w-16 text-2xl' : size === 'sm' ? 'h-8 w-8 text-xs' : 'h-11 w-11 text-base';
    return (
        <div className={`${box} grid place-items-center border border-line bg-ink font-display text-parchment`}>
            {letter}
        </div>
    );
}

const links = [
    ['Characters', '/characters'],
    ['Species', '/species'],
    ['Backgrounds', '/backgrounds'],
    ['Classes', '/classes'],
    ['Specializations', '/specializations'],
    ['Items', '/items'],
    ['Talents', '/talents'],
    ['Features', '/features'],
    ['Spells', '/spells'],
    ['Rules', '/rules'],
];

export function Logo({ className = 'w-full' }) {
    const { logo } = usePage().props;
    if (! logo) {
        return null;
    }

    return <img src={logo} alt="Eldritch Runes" className={`bg-transparent ${className}`} />;
}

export default function Shell({ title, children }) {
    const { auth, can, flash, logo } = usePage().props;
    const path = window.location.pathname;
    const [navOpen, setNavOpen] = useState(false);
    const closeNav = () => setNavOpen(false);

    return (
        <div className="min-h-screen bg-ink text-parchment">
            <header className="sticky top-0 z-30 flex items-center gap-3 border-b border-line bg-panel px-4 py-3 md:hidden">
                <button type="button" className="border border-line p-2 text-parchment" aria-label="Open menu" onClick={() => setNavOpen(true)}>
                    <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth="1.5">
                        <path d="M4 7h16M4 12h16M4 17h16" />
                    </svg>
                </button>
                <h1 className="min-w-0 truncate font-display text-2xl leading-none">{title}</h1>
            </header>
            {navOpen && <button type="button" className="fixed inset-0 z-40 bg-black/60 md:hidden" aria-label="Close menu" onClick={closeNav} />}
            <div className="flex min-h-screen">
                <aside className={`${navOpen ? 'translate-x-0' : '-translate-x-full'} fixed inset-y-0 left-0 z-50 flex h-screen w-72 shrink-0 flex-col border-r border-line bg-panel transition-transform md:sticky md:top-0 md:translate-x-0`}>
                    <Link href="/dashboard" className="border-b border-line px-3 py-4" onClick={closeNav}>
                        {logo ? <Logo /> : (
                            <div className="flex items-center gap-3">
                                <Mark letter="ER" />
                                <div>
                                    <div className="font-display text-xl leading-none">Eldritch Runes</div>
                                    <div className="mt-1 text-[10px] tracking-[0.22em] text-silver uppercase">Second Edition</div>
                                </div>
                            </div>
                        )}
                    </Link>
                    <nav className="flex-1 space-y-0.5 overflow-y-auto px-3 py-4">
                        {links.map(([label, href]) => (
                            <Link key={href} href={href} onClick={closeNav} className={`block rounded px-3 py-2 text-sm ${path.startsWith(href) ? 'bg-maroon-deep text-parchment' : 'text-parchment-dim hover:bg-panel-2 hover:text-parchment'}`}>
                                {label}
                            </Link>
                        ))}
                        {can?.campaigns && <div className="my-3 border-t border-line" />}
                        {can?.campaigns && (
                            <Link href="/campaigns" onClick={closeNav} className={`block rounded px-3 py-2 text-sm ${path.startsWith('/campaigns') ? 'bg-maroon-deep text-parchment' : 'text-silver hover:bg-panel-2'}`}>
                                Campaigns
                            </Link>
                        )}
                        {can?.gm && (
                            <Link href="/npcs" onClick={closeNav} className={`block rounded px-3 py-2 text-sm ${path.startsWith('/npcs') ? 'bg-maroon-deep text-parchment' : 'text-silver hover:bg-panel-2'}`}>
                                NPCs
                            </Link>
                        )}
                    </nav>
                    <div className="border-t border-line p-4">
                        <Link href="/player" onClick={closeNav} className={`block rounded px-1 py-1 ${path === '/player' ? 'text-parchment' : 'text-silver hover:text-parchment'}`}>
                            <div className="truncate text-sm">{auth.user?.name}</div>
                            <div className="text-[11px] tracking-widest text-maroon uppercase">{auth.user?.is_gm ? 'Game master' : 'Player'}</div>
                        </Link>
                        <Link href="/logout" method="post" as="button" className="mt-3 px-1 text-xs text-parchment-dim hover:text-parchment">Sign out</Link>
                    </div>
                </aside>
                <main className="min-w-0 flex-1">
                    <header className="hidden items-end justify-between border-b border-line px-8 py-6 md:flex">
                        <h1 className="font-display text-4xl leading-none">{title}</h1>
                    </header>
                    {flash?.success && <div className="mx-4 mt-6 border border-line bg-panel px-4 py-3 text-sm text-parchment md:mx-8">{flash.success}</div>}
                    {flash?.roll && !flash.roll.summary && (
                        <div className="mx-4 mt-4 border border-maroon bg-maroon-deep/40 px-4 py-3 text-sm md:mx-8">
                            {flash.roll.skill || 'Roll'} {flash.roll.display} {flash.roll.success ? 'succeeds' : 'fails'}
                            {flash.roll.critical ? ' — critical' : ''} {flash.roll.percent ? `(${flash.roll.percent}%)` : ''}
                            {flash.roll.modifier ? ` · ${flash.roll.modifier}` : ''}
                            {flash.roll.attack ? ` · ${flash.roll.attack}` : ''}
                            {flash.roll.damage != null ? ` · ${flash.roll.damage} wound${flash.roll.damage === 1 ? '' : 's'}` : ''}
                        </div>
                    )}
                    <div className="px-4 py-6 md:px-8">{children}</div>
                </main>
            </div>
        </div>
    );
}

export function Field({ label, children }) {
    return (
        <label className="block text-sm">
            <span className="mb-1 block text-[11px] tracking-[0.16em] text-silver uppercase">{label}</span>
            {children}
        </label>
    );
}

export const circumstances = [
    { value: 2, label: 'Greater Enhancement (+2)' },
    { value: 1, label: 'Enhancement (+1)' },
    { value: 0, label: 'No modifier' },
    { value: -1, label: 'Diminishment (−1)' },
    { value: -2, label: 'Greater Diminishment (−2)' },
];

const successByTier = [1, 5, 11, 17, 23, 29, 35, 41, 47, 53, 59, 65, 71, 77, 83, 89, 95, 99];

export function chanceLabel(tier) {
    const adjusted = Number(tier);
    if (!Number.isFinite(adjusted)) return null;
    if (adjusted <= -1) return 'impossible';
    if (adjusted >= 18) return '100% to hit';
    const percent = successByTier[adjusted];
    return percent == null ? null : `${percent}% to hit`;
}

export function successChance(tier) {
    const label = chanceLabel(tier);
    if (!label) return null;
    return label.replace(' to hit', '');
}

export function adjustedAttackTier(tier, suffering = 0, circumstance = 0) {
    const base = Number(tier);
    if (!Number.isFinite(base)) return null;
    return Math.max(0, base - Math.max(0, Math.min(2, Number(suffering) || 0))) + (Number(circumstance) || 0);
}

export function CircumstanceSelect({ value, onChange }) {
    return (
        <label className="mt-3 block text-xs tracking-widest text-silver uppercase">
            Circumstance
            <select className={`${inputClass} mt-1 normal-case`} value={value} onChange={(event) => onChange(Number(event.target.value))}>
                {circumstances.map((row) => <option key={row.value} value={row.value}>{row.label}</option>)}
            </select>
        </label>
    );
}

export const inputClass = 'w-full border border-line bg-ink px-3 py-2 text-parchment outline-none focus:border-silver';
export const buttonClass = 'border border-maroon bg-maroon-deep px-4 py-2 text-sm tracking-wide text-parchment hover:bg-maroon';
export const quietClass = 'border border-line px-4 py-2 text-sm text-silver hover:border-silver hover:text-parchment';
