import { Link } from '@inertiajs/react';
import Shell from '../Components/Shell';

export default function Dashboard({ counts }) {
    return (
        <Shell title="Dashboard">
            <div className="grid gap-4 md:grid-cols-3">
                <Link href="/characters" className="border border-line bg-panel p-6 hover:border-silver">
                    <div className="font-display text-5xl">{counts.characters}</div>
                    <div className="mt-2 text-sm tracking-widest text-silver uppercase">Characters</div>
                </Link>
                <Link href="/campaigns" className="border border-line bg-panel p-6 hover:border-silver">
                    <div className="font-display text-5xl">{counts.campaigns}</div>
                    <div className="mt-2 text-sm tracking-widest text-silver uppercase">Campaigns you keep</div>
                </Link>
                <Link href="/characters/create" className="border border-maroon bg-maroon-deep/30 p-6">
                    <div className="font-display text-3xl">Character Forge</div>
                    <div className="mt-2 text-sm text-parchment-dim">Species, background, class, and the road beyond the fires.</div>
                </Link>
            </div>
        </Shell>
    );
}
