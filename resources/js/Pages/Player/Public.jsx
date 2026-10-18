import Shell from '../../Components/Shell';

export default function Public({ profile }) {
    return (
        <Shell title={profile.name}>
            <article className="max-w-lg border border-line bg-panel p-6">
                <p className="text-[11px] tracking-widest text-maroon uppercase">{profile.is_gm ? 'Game master' : 'Player'}</p>
                <h2 className="mt-2 font-display text-4xl">{profile.name}</h2>
                {profile.description ? (
                    <p className="mt-4 whitespace-pre-wrap text-parchment">{profile.description}</p>
                ) : (
                    <p className="mt-4 text-sm text-silver">No description yet.</p>
                )}
            </article>
        </Shell>
    );
}
