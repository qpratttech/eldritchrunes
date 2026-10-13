import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import Shell, { CircumstanceSelect, Portrait, adjustedAttackTier, buttonClass, chanceLabel, inputClass, quietClass, successChance } from '../../Components/Shell';

function Tip({ text, children }) {
    if (!text) return children;
    return (
        <span className="group relative inline-block">
            {children}
            <span role="tooltip" className="pointer-events-none absolute bottom-full left-0 z-30 mb-2 hidden w-72 border border-line bg-ink p-3 text-left text-xs leading-5 whitespace-pre-wrap text-parchment normal-case shadow-xl group-hover:block">
                {text}
            </span>
        </span>
    );
}

function NpcRolls({ row, onRoll }) {
    const [circumstance, setCircumstance] = useState(0);
    return (
        <>
            <CircumstanceSelect value={circumstance} onChange={setCircumstance} />
            <Line title="Attributes" items={(row.attributes || []).map((item) => {
                const chance = successChance(adjustedAttackTier(item.tier, row.suffering, circumstance));
                return { ...item, chance: chance === 'impossible' ? 'Impossible' : chance ? `${chance} chance` : null };
            })} onRoll={(name) => onRoll(row.id, 'check', name, circumstance)} />
            <Line title="Skills" items={row.skills} onRoll={(name) => onRoll(row.id, 'skill', name, circumstance)} />
            <Line title="Attacks" items={(row.attacks || []).map((attack) => ({ ...attack, chance: attack.resolution === 'save' ? null : chanceLabel(adjustedAttackTier(attack.tier, row.suffering, circumstance)) }))} onRoll={(name) => onRoll(row.id, 'attack', name, circumstance)} />
        </>
    );
}

function Line({ title, items, onRoll }) {
    if (!items?.length) return null;
    return (
        <div className="mt-3">
            <h3 className="text-[11px] tracking-[0.16em] text-silver uppercase">{title}</h3>
            <div className="mt-1 flex flex-col gap-2 text-sm">
                {items.map((item) => (
                    <div key={item.name} className="flex items-center justify-between gap-3">
                        <span className="flex items-baseline gap-2">
                            <Tip text={item.tip}>
                                <span className="underline decoration-line decoration-dotted underline-offset-2">{item.name}</span>
                            </Tip>
                            {item.chance ? <span className="text-xs text-silver">{item.chance}</span> : null}
                        </span>
                        {onRoll && item.tier != null && <button className={quietClass} onClick={() => onRoll(item.key || item.name)}>Roll</button>}
                    </div>
                ))}
            </div>
        </div>
    );
}

export default function Index({ templates, campaigns }) {
    const form = useForm({ name: '', category: 'npc', description: '', base_wounds: 3, armor_save_percent: 0, attack_tier: 6 });
    const copy = useForm({ campaign_id: campaigns[0]?.id || '', name: '' });
    const lastRoll = usePage().props.flash?.roll;
    const art = usePage().props.art || {};

    function roll(id, type, name, circumstance) {
        router.post(`/npcs/${id}/rolls`, { type, name, circumstance }, { preserveScroll: true });
    }

    return (
        <Shell title="NPCs">
            <div className="grid gap-8 lg:grid-cols-[1fr_320px]">
                <div className="space-y-3">
                    {templates.map((row) => (
                        <article key={row.id} className="border border-line bg-panel p-4">
                            <div className="flex items-start justify-between gap-4">
                                <div className="flex min-w-0 gap-4">
                                    <Portrait src={row.portrait_url} name={row.name} size="xl" />
                                    <div className="min-w-0">
                                    <h2 className="font-display text-2xl">{row.name}</h2>
                                    <p className="flex flex-wrap items-center gap-x-1 text-sm text-silver">{row.category} · <img src={art.wounds} alt="" className="h-5 w-5" /> {row.wounds} wounds{row.stamina != null ? <> · <img src={art.stamina} alt="" className="h-5 w-5" /> stamina {row.stamina}</> : null} · <img src={art.power} alt="" className="h-5 w-5" /> power {row.power ?? 0} · <img src={art.focus} alt="" className="h-5 w-5" /> focus {row.focus ?? 0}{row.initiative != null ? <> · <img src={art.init} alt="" className="h-5 w-5" /> init {row.initiative >= 0 ? '+' : ''}{row.initiative}</> : null} · <img src={art.armor} alt="" className="h-5 w-5" /> {row.armor}%{row.armor_note ? ` — ${row.armor_note}` : ''} · <img src={art.move} alt="" className="h-5 w-5" /> {row.move}</p>
                                    <div className="mt-2 flex flex-wrap items-center gap-4 text-sm text-silver">
                                        <label className="flex items-center gap-1"><img src={art['major-action']} alt="" className="h-5 w-5" /> Major Action
                                            <input key={`${row.id}-major-${row.major_action ?? 1}`} className="w-14 border border-line bg-ink px-2 py-1 text-parchment" type="number" min="0" max="1" defaultValue={row.major_action ?? 1} onBlur={(e) => {
                                                const major = Math.max(0, Math.min(1, Number(e.target.value) || 0));
                                                e.target.value = major;
                                                router.patch(`/npcs/${row.id}`, { major_action: major }, { preserveScroll: true });
                                            }} />
                                            <span>/ 1</span>
                                        </label>
                                        <label className="flex items-center gap-1"><img src={art['minor-action']} alt="" className="h-5 w-5" /> Minor Action
                                            <input key={`${row.id}-minor-${row.minor_action ?? 2}`} className="w-14 border border-line bg-ink px-2 py-1 text-parchment" type="number" min="0" max="2" defaultValue={row.minor_action ?? 2} onBlur={(e) => {
                                                const minor = Math.max(0, Math.min(2, Number(e.target.value) || 0));
                                                e.target.value = minor;
                                                router.patch(`/npcs/${row.id}`, { minor_action: minor }, { preserveScroll: true });
                                            }} />
                                            <span>/ 2</span>
                                        </label>
                                    </div>
                                    <label className="mt-2 flex items-center gap-2 text-sm text-silver" title="Each rank imposes −1 tier, to a maximum of 2."><img src={art.suffering} alt="" className="h-5 w-5" /> Suffering
                                        <input key={`${row.id}-${row.suffering ?? 0}`} className="w-14 border border-line bg-ink px-2 py-1 text-parchment" type="number" min="0" max="2" defaultValue={row.suffering ?? 0} onBlur={(e) => {
                                            const suffering = Math.max(0, Math.min(2, Number(e.target.value) || 0));
                                            e.target.value = suffering;
                                            router.patch(`/npcs/${row.id}`, { suffering }, { preserveScroll: true });
                                        }} />
                                        <span>/ 2</span>
                                    </label>
                                    <p className="mt-2 text-sm text-parchment-dim">{row.description}</p>
                                    {row.behavior && <p className="mt-2 text-sm text-parchment-dim">{row.behavior}</p>}
                                    <NpcRolls row={row} onRoll={roll} />
                                    <Line title="Features" items={row.features} />
                                    {lastRoll?.npc_id === row.id && (
                                        <div className="mt-3 border border-maroon bg-maroon-deep/40 px-3 py-2 text-sm">
                                            {lastRoll.attack || lastRoll.skill}: rolled {lastRoll.display}
                                            {lastRoll.percent ? `, needing ${lastRoll.percent} or less` : ''}. {lastRoll.modifier ? `${lastRoll.modifier}. ` : ''}{lastRoll.save ? 'The target makes the save' : (lastRoll.attack ? (lastRoll.success ? 'Hit' : 'Miss') : (lastRoll.success ? 'Success' : 'Failure'))}
                                            {lastRoll.critical ? ', critical' : ''}
                                            {lastRoll.damage != null ? `. ${lastRoll.damage} wound${lastRoll.damage === 1 ? '' : 's'}` : ''}.
                                        </div>
                                    )}
                                    </div>
                                </div>
                                <form onSubmit={(e) => { e.preventDefault(); copy.post(`/npcs/${row.id}/copy`); }} className="flex flex-col gap-2">
                                    <select className={inputClass} value={copy.data.campaign_id} onChange={(e) => copy.setData('campaign_id', e.target.value)}>
                                        {campaigns.map((campaign) => <option key={campaign.id} value={campaign.id}>{campaign.name}</option>)}
                                    </select>
                                    <input className={inputClass} placeholder="Copy name" value={copy.data.name} onChange={(e) => copy.setData('name', e.target.value)} />
                                    <button className={buttonClass}>Copy into campaign</button>
                                </form>
                            </div>
                        </article>
                    ))}
                </div>
                <form onSubmit={(e) => { e.preventDefault(); form.post('/npcs'); }} className="space-y-3 border border-line bg-panel p-4">
                    <h2 className="font-display text-2xl">New creature</h2>
                    <input className={inputClass} placeholder="Name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                    <textarea className={inputClass} placeholder="Description" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                    <input className={inputClass} type="number" value={form.data.base_wounds} onChange={(e) => form.setData('base_wounds', e.target.value)} />
                    <button className={buttonClass}>Add to the tome</button>
                </form>
            </div>
        </Shell>
    );
}
