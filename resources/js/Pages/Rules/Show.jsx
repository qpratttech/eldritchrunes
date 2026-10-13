import { useEffect, useMemo, useState } from 'react';
import { usePage } from '@inertiajs/react';
import Shell, { inputClass } from '../../Components/Shell';

function termsOf(query) {
    return query.trim().toLowerCase().split(/\s+/).filter(Boolean);
}

function score(entry, terms) {
    const title = entry.title.toLowerCase();
    const text = `${title} ${entry.text.toLowerCase()}`;
    if (!terms.every((term) => text.includes(term))) return 0;
    let value = 1;
    if (terms.every((term) => title.includes(term))) value += 6;
    if (title === terms.join(' ')) value += 8;
    return value;
}

function excerpt(text, terms) {
    const lower = text.toLowerCase();
    const index = terms.reduce((found, term) => {
        const at = lower.indexOf(term);
        return at !== -1 && (found === -1 || at < found) ? at : found;
    }, -1);
    const start = Math.max(0, index - 70);
    const slice = text.slice(start, start + 240);
    const pattern = new RegExp(terms.map((term) => term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|'), 'ig');
    const pieces = [];
    let cursor = 0;
    for (const match of slice.matchAll(pattern)) {
        pieces.push(slice.slice(cursor, match.index));
        pieces.push(match[0]);
        cursor = match.index + match[0].length;
    }
    pieces.push(slice.slice(cursor));

    return { lead: start > 0, trail: start + 240 < text.length, pieces };
}

export default function Show({ sections, entries = [] }) {
    const chart = usePage().props.art?.['percentile-tiers'];
    const [query, setQuery] = useState('');
    const [jump, setJump] = useState(null);
    const terms = termsOf(query);
    const results = useMemo(() => {
        if (!terms.length) return [];
        return entries
            .map((entry) => ({ entry, score: score(entry, terms) }))
            .filter((row) => row.score > 0)
            .sort((a, b) => b.score - a.score || a.entry.title.localeCompare(b.entry.title));
    }, [entries, terms]);

    useEffect(() => {
        if (!jump || terms.length) return;
        document.getElementById(jump)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        setJump(null);
    }, [jump, terms.length]);

    return (
        <Shell title="Rules">
            {chart && <img src={chart} alt="System core mechanic: percentile tiers" className="mb-6 w-full border border-line" />}
            <input className={`${inputClass} mb-3`} placeholder="Search the core rules" value={query} onChange={(e) => setQuery(e.target.value)} />
            {terms.length > 0 && (
                <p className="mb-6 text-sm text-silver">{results.length === 0 ? `No rules match “${query.trim()}”.` : `${results.length} ${results.length === 1 ? 'match' : 'matches'}`}</p>
            )}
            {terms.length === 0 ? (
                <div className="space-y-8">
                    {sections.map((section) => (
                        <article id={section.id} key={section.id || section.title} className="min-w-0 scroll-mt-6 border border-line bg-panel p-6">
                            <h2 className="font-display text-3xl">{section.title}</h2>
                            {section.html ? <div className="rules-body mt-4 max-w-full text-sm leading-6 text-parchment-dim" dangerouslySetInnerHTML={{ __html: section.html }} /> : null}
                        </article>
                    ))}
                </div>
            ) : (
                <div className="space-y-4">
                    {results.slice(0, 40).map(({ entry }) => {
                        const snippet = excerpt(entry.text, terms);
                        return (
                            <article key={entry.id} className="border border-line bg-panel p-5">
                                <p className="text-xs tracking-widest text-silver uppercase">{entry.chapter}</p>
                                <button className="mt-1 text-left font-display text-2xl text-parchment hover:text-silver" onClick={() => { setJump(entry.id); setQuery(''); }}>{entry.title}</button>
                                <p className="mt-2 text-sm leading-6 text-parchment-dim">
                                    {snippet.lead ? '…' : ''}
                                    {snippet.pieces.map((piece, index) => index % 2 === 1 ? <mark key={index} className="bg-maroon-deep text-parchment">{piece}</mark> : <span key={index}>{piece}</span>)}
                                    {snippet.trail ? '…' : ''}
                                </p>
                            </article>
                        );
                    })}
                    {results.length > 40 && <p className="text-sm text-silver">Showing the first 40 matches. Add another word to narrow them.</p>}
                </div>
            )}
        </Shell>
    );
}
