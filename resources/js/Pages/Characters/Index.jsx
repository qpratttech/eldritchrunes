import { Link, router } from '@inertiajs/react';
import Shell, { Portrait, buttonClass, quietClass } from '../../Components/Shell';

export default function Index({ characters }) {
    function remove(character) {
        if (!window.confirm(`Remove ${character.name}? This also takes them out of any campaign.`)) return;
        router.delete(`/characters/${character.id}`);
    }

    return (
        <Shell title="Characters">
            <div className="mb-6 flex justify-end">
                <Link href="/characters/create" className={buttonClass}>Character Forge</Link>
            </div>
            <div className="grid gap-3">
                {characters.map((character) => (
                    <article key={character.id} className="flex items-center gap-4 border border-line bg-panel px-4 py-3">
                        <Link href={`/characters/${character.id}`} className="flex min-w-0 flex-1 items-center gap-4 hover:text-silver">
                            <Portrait src={character.portrait_url} name={character.name} />
                            <div className="min-w-0">
                                <div className="font-display text-2xl">{character.name}</div>
                                <div className="text-sm text-silver">{character.species} · {character.classes || 'Unclassed'}</div>
                            </div>
                        </Link>
                        <button type="button" className={quietClass} onClick={() => remove(character)}>Remove</button>
                    </article>
                ))}
                {characters.length === 0 && <p className="text-silver">No one has stepped forward yet.</p>}
            </div>
        </Shell>
    );
}
