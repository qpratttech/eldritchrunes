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
    const [creating, setCreating] = useState(false);
    const form = useForm(blankCreature());
    const copy = useForm({ campaign_id: campaigns[0]?.id || '', name: '' });
    const lastRoll = usePage().props.flash?.roll;
    const art = usePage().props.art || {};

    function roll(id, type, name, circumstance) {
        router.post(`/npcs/${id}/rolls`, { type, name, circumstance }, { preserveScroll: true });
    }

    function saveCreature(event) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            melee: data.melee === '' || data.melee === null ? null : Number(data.melee),
            ranged: data.ranged === '' || data.ranged === null ? null : Number(data.ranged),
            attacks: (data.attacks || []).map((attack) => ({
                ...attack,
                tier: attack.tier === '' || attack.tier === null ? null : Number(attack.tier),
                range_ft: attack.range_ft === '' || attack.range_ft === null ? null : Number(attack.range_ft),
            })),
        }));
        form.post('/npcs', {
            preserveScroll: true,
            onSuccess: () => {
                setCreating(false);
                form.reset();
            },
        });
    }

    return (
        <Shell title="NPCs">
            <div className="mb-4 flex justify-end">
                <button type="button" className={buttonClass} onClick={() => setCreating(true)}>New creature</button>
            </div>
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
            {creating && <CreatureForm form={form} onClose={() => setCreating(false)} onSubmit={saveCreature} />}
        </Shell>
    );
}

const blankAttack = () => ({ name: 'Strike', tier: '', range_ft: 5, damage: '1 Physical', resolution: 'attack', cost: 'major', tip: '', heavy: false, two_handed: false });

function blankCreature() {
    return {
        name: '',
        category: 'npc',
        description: '',
        behavior: '',
        base_wounds: 3,
        armor_save_percent: 0,
        armor_note: '',
        major_move_ft: 20,
        minor_move_ft: 10,
        stamina: 0,
        power: 0,
        focus: 0,
        initiative: 0,
        melee: '',
        ranged: '',
        attack_tier: 8,
        endurance: 8,
        reflexes: 8,
        willpower: 7,
        other: 6,
        skills: [],
        features: [],
        attacks: [blankAttack()],
    };
}

function CreatureForm({ form, onClose, onSubmit }) {
    function setList(key, index, patch) {
        form.setData(key, form.data[key].map((row, i) => (i === index ? { ...row, ...patch } : row)));
    }
    function addRow(key, row) {
        form.setData(key, [...form.data[key], row]);
    }
    function removeRow(key, index) {
        form.setData(key, form.data[key].filter((_, i) => i !== index));
    }
    return (
        <div className="fixed inset-0 z-[70] flex items-center justify-center bg-black/70 p-4" onClick={onClose}>
            <form onClick={(event) => event.stopPropagation()} onSubmit={onSubmit} className="flex max-h-[85vh] w-full max-w-3xl flex-col overflow-hidden border border-line bg-panel p-6 shadow-xl">
                <div className="flex items-start justify-between gap-4">
                    <h2 className="font-display text-3xl">New creature</h2>
                    <button type="button" className={quietClass} onClick={onClose}>Close</button>
                </div>
                <div className="mt-5 min-h-0 flex-1 space-y-6 overflow-y-auto pr-1">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="Name"><input className={inputClass} required value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></Field>
                        <Field label="Category">
                            <select className={inputClass} value={form.data.category} onChange={(e) => form.setData('category', e.target.value)}>
                                <option value="npc">NPC</option>
                                <option value="companion">Companion</option>
                                <option value="summon">Summon</option>
                            </select>
                        </Field>
                    </div>
                    <Field label="Description"><textarea className={inputClass} rows={3} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} /></Field>
                    <Field label="Behavior"><textarea className={inputClass} rows={2} value={form.data.behavior} onChange={(e) => form.setData('behavior', e.target.value)} /></Field>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <NumberField label="Wounds" value={form.data.base_wounds} onChange={(value) => form.setData('base_wounds', value)} />
                        <NumberField label="Armor %" value={form.data.armor_save_percent} onChange={(value) => form.setData('armor_save_percent', value)} />
                        <Field label="Armor note"><input className={inputClass} value={form.data.armor_note} onChange={(e) => form.setData('armor_note', e.target.value)} /></Field>
                        <NumberField label="Major move ft" value={form.data.major_move_ft} onChange={(value) => form.setData('major_move_ft', value)} />
                        <NumberField label="Minor move ft" value={form.data.minor_move_ft} onChange={(value) => form.setData('minor_move_ft', value)} />
                        <NumberField label="Initiative" value={form.data.initiative} onChange={(value) => form.setData('initiative', value)} />
                        <NumberField label="Stamina" value={form.data.stamina} onChange={(value) => form.setData('stamina', value)} />
                        <NumberField label="Power" value={form.data.power} onChange={(value) => form.setData('power', value)} />
                        <NumberField label="Focus" value={form.data.focus} onChange={(value) => form.setData('focus', value)} />
                    </div>
                    <div>
                        <h3 className="text-[11px] tracking-[0.16em] text-silver uppercase">Tiers</h3>
                        <div className="mt-2 grid gap-3 sm:grid-cols-4">
                            <NumberField label="Melee" value={form.data.melee} optional onChange={(value) => form.setData('melee', value)} />
                            <NumberField label="Ranged" value={form.data.ranged} optional onChange={(value) => form.setData('ranged', value)} />
                            <NumberField label="Attack" value={form.data.attack_tier} onChange={(value) => form.setData('attack_tier', value)} />
                            <NumberField label="Endurance" value={form.data.endurance} onChange={(value) => form.setData('endurance', value)} />
                            <NumberField label="Reflexes" value={form.data.reflexes} onChange={(value) => form.setData('reflexes', value)} />
                            <NumberField label="Willpower" value={form.data.willpower} onChange={(value) => form.setData('willpower', value)} />
                            <NumberField label="Other" value={form.data.other} onChange={(value) => form.setData('other', value)} />
                        </div>
                    </div>
                    <EntryList title="Attacks" rows={form.data.attacks} onAdd={() => addRow('attacks', blankAttack())} onRemove={(index) => removeRow('attacks', index)}>
                        {(row, index) => (
                            <div className="grid gap-2 sm:grid-cols-2">
                                <input className={inputClass} placeholder="Name" value={row.name} onChange={(e) => setList('attacks', index, { name: e.target.value })} />
                                <select className={inputClass} value={row.resolution} onChange={(e) => setList('attacks', index, { resolution: e.target.value })}>
                                    <option value="attack">Attack roll</option>
                                    <option value="save">Target saves</option>
                                </select>
                                <select className={inputClass} value={row.cost} onChange={(e) => setList('attacks', index, { cost: e.target.value })}>
                                    <option value="major">Major</option>
                                    <option value="minor">Minor</option>
                                    <option value="major and minor">Major and minor</option>
                                </select>
                                <input className={inputClass} placeholder="Damage" value={row.damage} onChange={(e) => setList('attacks', index, { damage: e.target.value })} />
                                <NumberField label="Tier" value={row.tier} optional onChange={(value) => setList('attacks', index, { tier: value })} />
                                <NumberField label="Range ft" value={row.range_ft} onChange={(value) => setList('attacks', index, { range_ft: value })} />
                                <input className={`${inputClass} sm:col-span-2`} placeholder="Notes" value={row.tip} onChange={(e) => setList('attacks', index, { tip: e.target.value })} />
                                <label className="flex items-center gap-2 text-sm normal-case tracking-normal text-parchment"><input type="checkbox" checked={row.heavy} onChange={(e) => setList('attacks', index, { heavy: e.target.checked })} /> Heavy</label>
                                <label className="flex items-center gap-2 text-sm normal-case tracking-normal text-parchment"><input type="checkbox" checked={row.two_handed} onChange={(e) => setList('attacks', index, { two_handed: e.target.checked })} /> Two-handed</label>
                            </div>
                        )}
                    </EntryList>
                    <EntryList title="Skills" rows={form.data.skills} onAdd={() => addRow('skills', { name: '', tip: '' })} onRemove={(index) => removeRow('skills', index)}>
                        {(row, index) => (
                            <div className="grid gap-2">
                                <input className={inputClass} placeholder="Skill" value={row.name} onChange={(e) => setList('skills', index, { name: e.target.value })} />
                                <input className={inputClass} placeholder="What it does" value={row.tip} onChange={(e) => setList('skills', index, { tip: e.target.value })} />
                            </div>
                        )}
                    </EntryList>
                    <EntryList title="Features" rows={form.data.features} onAdd={() => addRow('features', { name: '', tip: '' })} onRemove={(index) => removeRow('features', index)}>
                        {(row, index) => (
                            <div className="grid gap-2">
                                <input className={inputClass} placeholder="Feature" value={row.name} onChange={(e) => setList('features', index, { name: e.target.value })} />
                                <input className={inputClass} placeholder="What it does" value={row.tip} onChange={(e) => setList('features', index, { tip: e.target.value })} />
                            </div>
                        )}
                    </EntryList>
                    {form.errors.name && <p className="text-sm text-maroon">{form.errors.name}</p>}
                </div>
                <div className="mt-5 flex justify-end">
                    <button className={buttonClass} disabled={form.processing}>Add to the tome</button>
                </div>
            </form>
        </div>
    );
}

function EntryList({ title, rows, onAdd, onRemove, children }) {
    return (
        <div>
            <div className="flex items-center justify-between gap-3">
                <h3 className="text-[11px] tracking-[0.16em] text-silver uppercase">{title}</h3>
                <button type="button" className={quietClass} onClick={onAdd}>Add</button>
            </div>
            <div className="mt-2 space-y-3">
                {rows.map((row, index) => (
                    <div key={index} className="border border-line p-3">
                        {children(row, index)}
                        <button type="button" className="mt-2 text-xs text-silver underline" onClick={() => onRemove(index)}>Remove</button>
                    </div>
                ))}
            </div>
        </div>
    );
}

function Field({ label, children }) {
    return (
        <label className="block text-xs tracking-widest text-silver uppercase">{label}
            <span className="mt-1 block font-sans text-base normal-case tracking-normal">{children}</span>
        </label>
    );
}

function NumberField({ label, value, onChange, optional = false }) {
    return (
        <Field label={label}>
            <input className={inputClass} type="number" value={value ?? ''} onChange={(e) => onChange(optional && e.target.value === '' ? '' : Number(e.target.value))} />
        </Field>
    );
}
