import { useEffect, useState } from 'react';
import { useForm, router, usePage } from '@inertiajs/react';
import Shell, { CircumstanceSelect, Portrait, buttonClass, chanceLabel, inputClass, quietClass, successChance } from '../../Components/Shell';
import ConditionPicker, { addCharacterCondition, removeCharacterCondition } from '../../Components/ConditionPicker';
import LogText from '../../Components/LogText';

export default function Show({ sheet, classes, items = [], spells = [], techniques = [], attackSkills = [], talents = [], conditions = [], encounter, targets = [] }) {
    const [target, setTarget] = useState('');
    const [circumstance, setCircumstance] = useState(0);
    const [spellQuery, setSpellQuery] = useState('');
    const [techniqueQuery, setTechniqueQuery] = useState('');
    const [advance, setAdvance] = useState(null);
    const details = useForm({
        name: sheet.name || '',
        xp: sheet.xp || 0,
        gender: sheet.gender || '', pronouns: sheet.pronouns || '', height: sheet.height || '',
        weight: sheet.weight || '', age: sheet.age || '', eyes: sheet.eyes || '', hair: sheet.hair || '',
        biography: sheet.biography || '', notes: sheet.notes || '',
        suffering: sheet.suffering ?? 0,
        resources: {
            wounds: sheet.resources.wounds?.current ?? 0,
            stamina: sheet.resources.stamina?.current ?? 0,
            luck: sheet.resources.luck?.current ?? 0,
            sorcerous_power: sheet.resources.sorcerous_power?.current ?? 0,
            focus: sheet.resources.focus?.current ?? 0,
            major_action: sheet.resources.major_action?.current ?? 1,
            minor_action: sheet.resources.minor_action?.current ?? 2,
        },
        gold: sheet.gold ?? 0,
        silver: sheet.silver ?? 0,
    });
    useEffect(() => {
        details.setData('resources', {
            ...details.data.resources,
            major_action: sheet.resources.major_action?.current ?? 1,
            minor_action: sheet.resources.minor_action?.current ?? 2,
        });
    }, [sheet.resources.major_action?.current, sheet.resources.minor_action?.current]);
    useEffect(() => {
        details.setData('gold', sheet.gold ?? 0);
        details.setData('silver', sheet.silver ?? 0);
    }, [sheet.gold, sheet.silver]);
    const mine = encounter?.combatants?.find((row) => row.character_id === sheet.id);
    const lastRoll = usePage().props.flash?.roll;
    const errors = usePage().props.errors || {};
    const art = usePage().props.art || {};
    const attackResult = lastRoll?.summary && lastRoll.attack ? lastRoll : null;

    useEffect(() => {
        if (!attackResult) return;
        document.getElementById('last-attack-roll')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }, [attackResult?.summary, attackResult?.roll]);

    function rollAttack(row, heavy = false) {
        const [targetType, targetId] = (target || '').split(':');
        router.post(`/characters/${sheet.id}/attacks`, {
            kind: row.kind,
            item_id: row.item_id,
            spell_id: row.spell_id,
            heavy,
            combatant_id: mine?.id,
            target_type: targetType || null,
            target_id: targetId || null,
            circumstance,
        }, { preserveScroll: true });
    }

    function roll(skillId) {
        router.post(`/characters/${sheet.id}/rolls`, { skill_id: skillId, circumstance, combatant_id: mine?.id }, { preserveScroll: true });
    }

    function rollAttribute(key) {
        router.post(`/characters/${sheet.id}/rolls`, { attribute: key, circumstance, combatant_id: mine?.id }, { preserveScroll: true });
    }

    function advanceClass(event) {
        event.preventDefault();
        const classId = event.target.class_id.value;
        const chosen = classes.find((row) => String(row.id) === String(classId));
        const current = (sheet.classes || []).find((row) => row.name === chosen?.name);
        const nextLevel = current ? Number(current.level) + 1 : 1;
        const total = (sheet.classes || []).reduce((sum, row) => sum + Number(row.level), 0) + 1;
        const prompts = [];
        const entry = chosen?.progression?.find((row) => row.level === nextLevel);
        if (entry?.choice?.length) prompts.push({ key: entry.feature, feature: entry.feature, kinds: entry.choice, spell: entry.spell });
        const spec = (sheet.specialization_progression || []).find((row) => row.level === total);
        if (spec?.choice?.length) prompts.push({ key: spec.feature, feature: `${sheet.specialization} · ${spec.feature}`, kinds: spec.choice, spell: spec.spell });
        if (prompts.length) {
            setAdvance({ classId, prompts });
            return;
        }
        router.post(`/characters/${sheet.id}/classes`, { class_id: classId }, { preserveScroll: true });
    }

    return (
        <Shell title={sheet.name}>
            <div className="grid gap-6 xl:grid-cols-[24rem_1fr_300px]">
                <section className="border border-line bg-panel p-4">
                    <Portrait src={sheet.portrait_url} name={sheet.name} size="xl" />
                    <input className={`${inputClass} mt-4`} value={details.data.name} onChange={(e) => details.setData('name', e.target.value)} />
                    <p className="mt-2 text-sm text-silver">{sheet.species} · {sheet.background}</p>
                    <p className="font-display text-2xl">
                        {sheet.specialization && (
                            <>
                                <FeatureTip text={sheet.specialization_detail}>
                                    <span className="underline decoration-line decoration-dotted underline-offset-4">{sheet.specialization}</span>
                                </FeatureTip>
                                {' '}
                            </>
                        )}
                        {sheet.classes.length > 0
                            ? sheet.classes.map((row, index) => (
                                <span key={row.id || row.name}>
                                    {index > 0 && ' / '}
                                    <FeatureTip text={row.detail}>
                                        <span className="underline decoration-line decoration-dotted underline-offset-4">{row.name}</span>
                                    </FeatureTip>
                                    {` ${row.level}`}
                                </span>
                            ))
                            : 'Level 1'}
                    </p>
                    <label className="mt-1 block text-sm text-parchment-dim">Level {sheet.level} · XP
                        <input className={`${inputClass} mt-1`} type="number" min="0" value={details.data.xp} onChange={(e) => details.setData('xp', Number(e.target.value))} />
                    </label>
                    <dl className="mt-4 space-y-2 text-sm">
                        <div className="flex items-center justify-between gap-2">
                            <StatLabel icon={art['major-action']} label="Major Action" tip={statTips.major} />
                            <dd className="flex items-center gap-1">
                                <input className="w-14 border border-line bg-ink px-2 py-1" type="number" min="0" max="1" value={details.data.resources.major_action ?? 1} onChange={(e) => details.setData('resources', { ...details.data.resources, major_action: Math.max(0, Math.min(1, Number(e.target.value) || 0)) })} />
                                <span>/ 1</span>
                            </dd>
                        </div>
                        <div className="flex items-center justify-between gap-2">
                            <StatLabel icon={art['minor-action']} label="Minor Action" tip={statTips.minor} />
                            <dd className="flex items-center gap-1">
                                <input className="w-14 border border-line bg-ink px-2 py-1" type="number" min="0" max="2" value={details.data.resources.minor_action ?? 2} onChange={(e) => details.setData('resources', { ...details.data.resources, minor_action: Math.max(0, Math.min(2, Number(e.target.value) || 0)) })} />
                                <span>/ 2</span>
                            </dd>
                        </div>
                        <ResourceEditor label="Wounds" icon={art.wounds} tip={statTips.wounds} resource={sheet.resources.wounds} value={details.data.resources.wounds} onChange={(value) => details.setData('resources', { ...details.data.resources, wounds: value })} />
                        <div className="flex items-center justify-between gap-2">
                            <StatLabel icon={art.suffering} label="Suffering" tip={statTips.suffering} />
                            <dd className="flex items-center gap-1">
                                <input className="w-14 border border-line bg-ink px-2 py-1" type="number" min="0" max="2" value={details.data.suffering} onChange={(e) => details.setData('suffering', Math.max(0, Math.min(2, Number(e.target.value) || 0)))} />
                                <span>/ 2</span>
                            </dd>
                        </div>
                        <ResourceEditor label="Stamina" icon={art.stamina} tip={statTips.stamina} resource={sheet.resources.stamina} value={details.data.resources.stamina} onChange={(value) => details.setData('resources', { ...details.data.resources, stamina: value })} />
                        <ResourceEditor label="Luck" icon={art.luck} tip={statTips.luck} resource={sheet.resources.luck} value={details.data.resources.luck} onChange={(value) => details.setData('resources', { ...details.data.resources, luck: value })} />
                        <ResourceEditor label="Power" icon={art.power} tip={statTips.power} resource={sheet.resources.sorcerous_power} value={details.data.resources.sorcerous_power} onChange={(value) => details.setData('resources', { ...details.data.resources, sorcerous_power: value })} />
                        <Resource label="Potential" icon={art.potential} tip={statTips.potential} value={sheet.resources.sorcerous_potential} />
                        <Resource label="Soul" icon={art.soul} tip={statTips.soul} value={sheet.resources.sorcerous_soul} />
                        <Resource label="Focus" icon={art.focus} tip={statTips.focus} value={sheet.resources.focus || { current: 0, max: 0 }} />
                        <div className="flex justify-between"><StatLabel icon={art.crit} label="Crit" tip={statTips.crit} /><dd>01–{String(sheet.resources.crit_range?.max || 3).padStart(2, '0')}</dd></div>
                        <div className="flex justify-between"><StatLabel icon={art.init} label="Init" tip={statTips.init} /><dd>{(sheet.resources.initiative_bonus?.max ?? 0) >= 0 ? `+${sheet.resources.initiative_bonus?.max ?? 0}` : sheet.resources.initiative_bonus?.max}</dd></div>
                        <div className="flex justify-between"><StatLabel icon={art.armor} label="Armor" tip={statTips.armor} /><dd>{sheet.armor ?? 0}%</dd></div>
                        <div className="flex justify-between"><StatLabel icon={art.move} label="Move" tip={statTips.move} /><dd>{sheet.resources.movement_major?.max}/{sheet.resources.movement_minor?.max} ft</dd></div>
                        <div className="flex items-center justify-between gap-2">
                            <StatLabel icon={art.coin} label="Coin" tip={statTips.coin} />
                            <dd className="flex items-center gap-1">
                                <input className="w-28 shrink-0 border border-line bg-ink px-2 py-1 tabular-nums" type="number" min="0" value={details.data.gold} onChange={(e) => details.setData('gold', Math.max(0, Number(e.target.value) || 0))} />
                                <span>g</span>
                                <input className="w-28 shrink-0 border border-line bg-ink px-2 py-1 tabular-nums" type="number" min="0" value={details.data.silver} onChange={(e) => details.setData('silver', Math.max(0, Number(e.target.value) || 0))} />
                                <span>s</span>
                            </dd>
                        </div>
                    </dl>
                    <ConditionPicker
                        applied={sheet.conditions || []}
                        catalog={conditions}
                        canEdit
                        onAdd={(conditionId) => addCharacterCondition(sheet.id, conditionId)}
                        onRemove={(id) => removeCharacterCondition(sheet.id, id)}
                    />
                    <button className={`${buttonClass} mt-4`} onClick={() => details.patch(`/characters/${sheet.id}`)}>Save sheet</button>
                </section>
                <section>
                    <CircumstanceSelect value={circumstance} onChange={setCircumstance} />
                    <h2 className="mt-6 font-display text-3xl">Attacks</h2>
                    {(targets.length > 0 || encounter) && (
                        <select className={`${inputClass} mt-3`} value={target} onChange={(e) => setTarget(e.target.value)}>
                            <option value="">No target — roll only</option>
                            {targets.map((row) => (
                                <option key={`${row.type}-${row.id}`} value={`${row.type}:${row.id}`}>{row.name}{row.wounds != null ? ` · ${row.wounds} wounds` : ''}</option>
                            ))}
                        </select>
                    )}
                    {(errors.action || errors.attack) && <p className="mt-3 text-sm text-maroon">{errors.action || errors.attack}</p>}
                    <div className="mt-3 divide-y divide-line border border-line">
                        {sheet.attacks.map((attack) => {
                            const rolled = attackResult && (attack.name === attackResult.attack || attack.name.startsWith(`${attackResult.attack} `) || attack.name.startsWith(attackResult.attack));
                            return (
                                <div key={`${attack.kind}-${attack.name}`} className="px-3 py-2">
                                    <div className="flex items-center justify-between gap-3">
                                        <div>
                                            <div>{attack.name}</div>
                                            <div className="text-xs text-parchment-dim">{attack.detail}{attack.tier != null ? ` · ${chanceLabel(attack.tier + circumstance)}` : ''}</div>
                                        </div>
                                        <div className="flex gap-2">
                                            <button className={quietClass} onClick={() => rollAttack(attack)}>Roll</button>
                                            {attack.heavy && <button className={quietClass} onClick={() => rollAttack(attack, true)}>Heavy</button>}
                                        </div>
                                    </div>
                                    {rolled && (
                                        <div id="last-attack-roll" className="mt-2 border border-maroon bg-maroon-deep/40 px-3 py-2 text-sm">
                                            <LogText text={attackResult.summary} />
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                    {attackResult && !sheet.attacks.some((attack) => attack.name === attackResult.attack || attack.name.startsWith(attackResult.attack)) && (
                        <div id="last-attack-roll" className="mt-3 border border-maroon bg-maroon-deep/40 px-4 py-3 text-sm">
                            <LogText text={attackResult.summary} />
                        </div>
                    )}
                    <h2 className="mt-8 font-display text-3xl">Skills</h2>
                    {lastRoll?.summary && !lastRoll.attack && !lastRoll.attribute && (
                        <div className="mt-3 border border-maroon bg-maroon-deep/40 px-4 py-3 text-sm">
                            <LogText text={lastRoll.summary} />
                        </div>
                    )}
                    <div className="mt-3 divide-y divide-line border border-line">
                        {sheet.skills.filter((skill) => skill.rank > 0 || skill.attack).map((skill) => (
                            <div key={skill.key} className="flex items-center justify-between gap-3 px-3 py-2">
                                <div>
                                    <div>{skill.name} <span className="text-xs text-silver">{skill.attribute} {skill.rank}</span></div>
                                    <div className="text-xs text-parchment-dim">Tier {skill.tier} · {skill.outcome === 'roll' ? `${skill.percent}%` : skill.outcome}</div>
                                </div>
                                <button className={quietClass} onClick={() => roll(skill.id)}>Roll</button>
                            </div>
                        ))}
                    </div>
                    {mine && (
                        <div className="mt-4 flex flex-wrap gap-2">
                            {['major', 'minor', 'free'].map((kind) => (
                                <button key={kind} className={buttonClass} onClick={() => router.post(`/combatants/${mine.id}/actions`, { kind, note: `${kind} action from the sheet` })}>{kind}</button>
                            ))}
                        </div>
                    )}
                    <h2 className="mt-8 font-display text-3xl">Attributes</h2>
                    {lastRoll?.attribute && lastRoll.summary && (
                        <div className="mt-3 border border-maroon bg-maroon-deep/40 px-4 py-3 text-sm">
                            <LogText text={lastRoll.summary} />
                        </div>
                    )}
                    <div className="mt-3 grid grid-cols-3 gap-2">
                        {sheet.attributes.map((row) => {
                            const chance = successChance(Number(row.tier) + circumstance);
                            return (
                                <div key={row.key} className="border border-line px-3 py-2">
                                    <div className="text-xs text-silver uppercase">{row.name}</div>
                                    <div className="font-display text-3xl">{row.step}</div>
                                    <div className="text-xs text-parchment-dim">{chance === 'impossible' ? 'Impossible' : `${chance} chance`}</div>
                                    <button type="button" className={`${quietClass} mt-2`} onClick={() => rollAttribute(row.key)}>Roll</button>
                                </div>
                            );
                        })}
                    </div>
                </section>
                <section className="space-y-4">
                    <div className="border border-line bg-panel p-4">
                        <h2 className="font-display text-2xl">Features</h2>
                        {sheet.features.map((feature, index) => (
                            <p key={`${feature.name}-${index}`} className="mt-2 text-sm">
                                <FeatureTip text={featureDetail(feature)} align="end">
                                    <span className="text-parchment underline decoration-line decoration-dotted underline-offset-2">{feature.name}</span>
                                </FeatureTip>
                                {' '}
                                <span className="text-silver">({feature.source})</span>
                            </p>
                        ))}
                    </div>
                    {sheet.companion && (
                        <div className="border border-line bg-panel p-4">
                            <h2 className="font-display text-2xl">Companion</h2>
                            {sheet.companion.name ? (
                                <p className="mt-2 text-sm"><span className="text-parchment">{sheet.companion.name}</span>{sheet.companion.aptitude ? <span className="text-silver"> · {sheet.companion.aptitude} Enhanced</span> : null}</p>
                            ) : <p className="mt-2 text-sm text-silver">Choose the companion that travels with you.</p>}
                            {sheet.companion.detail && <p className="mt-2 text-sm text-parchment-dim">{sheet.companion.detail}</p>}
                            <form className="mt-4 grid gap-2" onSubmit={(event) => {
                                event.preventDefault();
                                router.post(`/characters/${sheet.id}/companion`, {
                                    name: event.target.name.value,
                                    aptitude: event.target.aptitude?.value || null,
                                }, { preserveScroll: true });
                            }}>
                                <select name="name" className={inputClass} defaultValue={sheet.companion.name || ''}>
                                    <option value="">Choose a companion</option>
                                    {(sheet.companion.grant === 'small' ? [] : ['Direwolf', 'Panther', 'Bear']).map((name) => <option key={name} value={name}>{name}</option>)}
                                    {(sheet.companion.grant === 'greater' ? [] : ['Raven', 'Owl', 'Eagle', 'Monkey', 'Snake', 'Bat']).map((name) => <option key={name} value={name}>{name}</option>)}
                                </select>
                                {sheet.companion.grant !== 'greater' && (
                                    <select name="aptitude" className={inputClass} defaultValue={sheet.companion.aptitude || ''}>
                                        <option value="">Small companion aptitude</option>
                                        {['Awareness', 'Stealth', 'Athletics'].map((name) => <option key={name} value={name}>{name}</option>)}
                                    </select>
                                )}
                                <button className={buttonClass}>Save companion</button>
                                {errors.name && <p className="text-sm text-maroon">{errors.name}</p>}
                                {errors.aptitude && <p className="text-sm text-maroon">{errors.aptitude}</p>}
                            </form>
                        </div>
                    )}
                    <div className="border border-line bg-panel p-4">
                        <h2 className="font-display text-2xl">Spells</h2>
                        {(sheet.spells || []).length === 0 && <p className="mt-2 text-sm text-silver">No spells known.</p>}
                        {(sheet.spells || []).map((spell) => (
                            <p key={spell.id} className="mt-2 flex items-center justify-between gap-2 text-sm">
                                <span>
                                    <FeatureTip text={spell.detail} align="end">
                                        <span className="underline decoration-line decoration-dotted underline-offset-2">{spell.name}</span>
                                    </FeatureTip>
                                    <span className="text-silver"> · {spell.order} · Level {spell.tier}</span>
                                </span>
                                <button type="button" className="text-xs text-silver underline" onClick={() => router.delete(`/characters/${sheet.id}/spells/${spell.id}`, { preserveScroll: true })}>Remove</button>
                            </p>
                        ))}
                        <form className="mt-4 grid gap-2" onSubmit={(event) => {
                            event.preventDefault();
                            if (!event.target.spell_id.value) return;
                            router.post(`/characters/${sheet.id}/spells`, { spell_id: event.target.spell_id.value }, { preserveScroll: true, onSuccess: () => { event.target.reset(); setSpellQuery(''); } });
                        }}>
                            <input className={inputClass} placeholder="Search spells" value={spellQuery} onChange={(event) => setSpellQuery(event.target.value)} />
                            <div className="flex gap-2">
                                <select name="spell_id" className={inputClass} defaultValue="">
                                    <option value="">Choose a spell</option>
                                    {spells.filter((spell) => {
                                        const known = (sheet.spells || []).some((knownSpell) => knownSpell.spell_id === spell.id);
                                        const terms = spellQuery.trim().toLowerCase();
                                        const text = `${spell.name} ${spell.order || ''} ${spell.tier}`.toLowerCase();
                                        return !known && (!terms || text.includes(terms));
                                    }).map((spell) => <option key={spell.id} value={spell.id}>{spell.name} · {spell.order} {spell.tier}</option>)}
                                </select>
                                <button className={buttonClass}>Add</button>
                            </div>
                            {errors.spell_id && <p className="text-sm text-maroon">{errors.spell_id}</p>}
                        </form>
                    </div>
                    <div className="border border-line bg-panel p-4">
                        <h2 className="font-display text-2xl">Weapon Techniques</h2>
                        {(sheet.techniques || []).length === 0 && <p className="mt-2 text-sm text-silver">No weapon techniques known.</p>}
                        {(sheet.techniques || []).map((row) => (
                            <p key={row.id} className="mt-2 flex items-center justify-between gap-2 text-sm">
                                <span>
                                    <FeatureTip text={row.detail} align="end">
                                        <span className="underline decoration-line decoration-dotted underline-offset-2">{row.name}</span>
                                    </FeatureTip>
                                    <span className="text-silver"> · {row.weapon}</span>
                                </span>
                                <button type="button" className="text-xs text-silver underline" onClick={() => router.delete(`/characters/${sheet.id}/techniques/${row.id}`, { preserveScroll: true })}>Remove</button>
                            </p>
                        ))}
                        <form className="mt-4 grid gap-2" onSubmit={(event) => {
                            event.preventDefault();
                            if (!event.target.weapon_type_id.value) return;
                            router.post(`/characters/${sheet.id}/techniques`, { weapon_type_id: event.target.weapon_type_id.value }, { preserveScroll: true, onSuccess: () => { event.target.reset(); setTechniqueQuery(''); } });
                        }}>
                            <input className={inputClass} placeholder="Search weapon techniques" value={techniqueQuery} onChange={(event) => setTechniqueQuery(event.target.value)} />
                            <div className="flex gap-2">
                                <select name="weapon_type_id" className={inputClass} defaultValue="">
                                    <option value="">Choose a weapon</option>
                                    {techniques.filter((row) => {
                                        const known = (sheet.techniques || []).some((knownRow) => knownRow.weapon_type_id === row.id);
                                        const terms = techniqueQuery.trim().toLowerCase();
                                        const text = `${row.weapon} ${row.technique}`.toLowerCase();
                                        return !known && (!terms || text.includes(terms));
                                    }).map((row) => <option key={row.id} value={row.id}>{row.weapon} · {row.technique}</option>)}
                                </select>
                                <button className={buttonClass}>Add</button>
                            </div>
                            {errors.weapon_type_id && <p className="text-sm text-maroon">{errors.weapon_type_id}</p>}
                        </form>
                    </div>
                    <div className="border border-line bg-panel p-4">
                        <h2 className="font-display text-2xl">Spell Techniques</h2>
                        <p className="mt-1 text-sm text-silver">One learned technique can shape any eligible spell. Only one technique applies to a casting.</p>
                        {(sheet.spell_techniques || []).length === 0 && <p className="mt-2 text-sm text-silver">No spell techniques known.</p>}
                        {(sheet.spell_techniques || []).map((name) => (
                            <p key={name} className="mt-2 flex items-center justify-between gap-2 text-sm">
                                <span>
                                    <span className="text-parchment">{name}</span>
                                    <span className="text-silver"> · {spellTechniqueDetail(name)}</span>
                                </span>
                                <button type="button" className="text-xs text-silver underline" onClick={() => router.delete(`/characters/${sheet.id}/spell-techniques/${encodeURIComponent(name)}`, { preserveScroll: true })}>Remove</button>
                            </p>
                        ))}
                        <form className="mt-4 flex gap-2" onSubmit={(event) => {
                            event.preventDefault();
                            if (!event.target.name.value) return;
                            router.post(`/characters/${sheet.id}/spell-techniques`, { name: event.target.name.value }, { preserveScroll: true, onSuccess: () => event.target.reset() });
                        }}>
                            <select name="name" className={inputClass} defaultValue="">
                                <option value="">Choose a technique</option>
                                {['Potent', 'Far-Reaching', 'Lasting', 'Subtle'].filter((name) => !(sheet.spell_techniques || []).includes(name)).map((name) => <option key={name} value={name}>{name}</option>)}
                            </select>
                            <button className={buttonClass}>Add</button>
                        </form>
                        {errors.name && <p className="mt-2 text-sm text-maroon">{errors.name}</p>}
                    </div>
                    <div className="border border-line bg-panel p-4">
                        <h2 className="font-display text-2xl">Inventory</h2>
                        {sheet.inventory.length === 0 && <p className="mt-2 text-sm text-silver">Nothing carried.</p>}
                        {sheet.inventory.map((item) => (
                            <p key={item.id} className="mt-2 flex items-center justify-between gap-2 text-sm">
                                <FeatureTip text={item.detail} align="end">
                                    <span className={item.detail ? 'underline decoration-line decoration-dotted underline-offset-2' : ''}>{item.quantity}× {item.name}{item.equipped ? (item.category === 'armor' ? ' (worn)' : ' (wielded)') : ''}</span>
                                </FeatureTip>
                                <span className="flex gap-3">
                                    {item.category === 'weapon' && <button type="button" className="text-xs text-silver underline" onClick={() => router.post(`/characters/${sheet.id}/equipment/${item.id}/equip`, {}, { preserveScroll: true })}>{item.equipped ? 'Stow' : 'Wield'}</button>}
                                    {item.category === 'armor' && <button type="button" className="text-xs text-silver underline" onClick={() => router.post(`/characters/${sheet.id}/equipment/${item.id}/equip`, {}, { preserveScroll: true })}>{item.equipped ? 'Unequip' : 'Equip'}</button>}
                                    <button type="button" className="text-xs text-silver underline" onClick={() => router.delete(`/characters/${sheet.id}/inventory/${item.id}`, { preserveScroll: true })}>Remove</button>
                                </span>
                            </p>
                        ))}
                        <form className="mt-4 grid gap-2" onSubmit={(event) => {
                            event.preventDefault();
                            const itemId = event.target.item_id.value;
                            router.post(`/characters/${sheet.id}/inventory`, {
                                item_id: itemId || null,
                                name: itemId ? null : event.target.name.value,
                                quantity: Number(event.target.quantity.value) || 1,
                            }, { preserveScroll: true, onSuccess: () => event.target.reset() });
                        }}>
                            <select name="item_id" className={inputClass} defaultValue="">
                                <option value="">Catalog item</option>
                                {items.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
                            </select>
                            <input name="name" className={inputClass} placeholder="Or a custom item" />
                            <div className="flex gap-2">
                                <input name="quantity" className="w-16 border border-line bg-ink px-2 py-1" type="number" min="1" max="99" defaultValue="1" />
                                <button className={buttonClass}>Add</button>
                            </div>
                            {errors.name && <p className="text-sm text-maroon">{errors.name}</p>}
                        </form>
                    </div>
                    <form onSubmit={advanceClass} className="flex gap-2">
                        <select name="class_id" className={inputClass}>{classes.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</select>
                        <button className={buttonClass}>Advance</button>
                    </form>
                    {errors.choices && <p className="text-sm text-maroon">{errors.choices}</p>}
                    {errors.class_id && <p className="text-sm text-maroon">{errors.class_id}</p>}
                    {advance && (
                        <AdvanceDialog
                            advance={advance}
                            setAdvance={setAdvance}
                            sheet={sheet}
                            spells={spells}
                            techniques={techniques}
                            attackSkills={attackSkills}
                            talents={talents}
                            onSave={(choices) => router.post(`/characters/${sheet.id}/classes`, { class_id: advance.classId, choices }, { preserveScroll: true, onSuccess: () => setAdvance(null) })}
                        />
                    )}
                </section>
            </div>
            <form onSubmit={(e) => { e.preventDefault(); details.patch(`/characters/${sheet.id}`); }} className="mt-8 grid gap-3 border border-line p-4 md:grid-cols-4">
                {['gender', 'pronouns', 'height', 'weight', 'age', 'eyes', 'hair'].map((field) => (
                    <label key={field} className="text-xs tracking-widest text-silver uppercase">{field}
                        <input className={`${inputClass} mt-1`} value={details.data[field]} onChange={(e) => details.setData(field, e.target.value)} />
                    </label>
                ))}
                <label className="text-xs tracking-widest text-silver uppercase md:col-span-2">Biography
                    <textarea className={`${inputClass} mt-1`} rows={3} value={details.data.biography} onChange={(e) => details.setData('biography', e.target.value)} />
                </label>
                <label className="text-xs tracking-widest text-silver uppercase md:col-span-2">Notes
                    <textarea className={`${inputClass} mt-1`} rows={3} value={details.data.notes} onChange={(e) => details.setData('notes', e.target.value)} />
                </label>
                <button className={buttonClass}>Save sheet</button>
            </form>
            <details className="mt-6 text-sm text-parchment-dim">
                <summary className="cursor-pointer text-silver">Where the numbers came from</summary>
                <ul className="mt-2 list-disc pl-5">{sheet.ledger.map((line) => <li key={line}>{line}</li>)}</ul>
            </details>
        </Shell>
    );
}

function spellTechniqueDetail(name) {
    return {
        Potent: 'An Empowered spell deals 1 extra wound to one target before defenses.',
        'Far-Reaching': 'Adds 30 feet to a numerical casting range.',
        Lasting: 'Doubles a Focus spell’s listed duration.',
        Subtle: 'Removes ordinary casting gestures and speech.',
    }[name] || '';
}

function AdvanceDialog({ advance, setAdvance, sheet, spells, techniques, attackSkills, talents, onSave }) {
    const [choices, setChoices] = useState({});
    const potential = Number(sheet.resources?.sorcerous_potential?.max ?? 0);
    const orders = sheet.orders || [];
    function setChoice(key, patch) {
        setChoices((current) => ({ ...current, [key]: { ...(current[key] || {}), ...patch } }));
    }
    return (
        <div className="fixed inset-0 z-[70] flex items-center justify-center bg-black/70 p-4" onClick={() => setAdvance(null)}>
            <div role="dialog" aria-modal="true" className="max-h-[85vh] w-full max-w-2xl overflow-y-auto border border-line bg-panel p-6 shadow-xl" onClick={(event) => event.stopPropagation()}>
                <h2 className="font-display text-3xl">Special Option</h2>
                <div className="mt-5 space-y-6">
                    {advance.prompts.map((prompt) => (
                        <section key={prompt.key}>
                            <h3 className="font-display text-2xl">{prompt.feature}</h3>
                            {prompt.kinds.map((kind) => (
                                <ChoiceField key={kind} kind={kind} spell={prompt.spell} value={choices[prompt.key] || {}} onChange={(patch) => setChoice(prompt.key, patch)} spells={spells} techniques={techniques} attackSkills={attackSkills} talents={talents} potential={potential} orders={orders} />
                            ))}
                        </section>
                    ))}
                </div>
                <div className="mt-6 flex justify-end gap-2">
                    <button type="button" className={quietClass} onClick={() => setAdvance(null)}>Close</button>
                    <button type="button" className={buttonClass} onClick={() => onSave(choices)}>OK</button>
                </div>
            </div>
        </div>
    );
}

function ChoiceField({ kind, spell = null, value, onChange, spells, techniques, attackSkills, talents, potential, orders }) {
    if (kind === 'attack_skill') {
        return <select className={`${inputClass} mt-3`} value={value.skill || ''} onChange={(event) => onChange({ skill: event.target.value })}>
            <option value="">Attack skill</option>
            {attackSkills.filter((skill) => ['melee', 'ranged', 'unarmed', 'sorcery', 'faith', 'shamanism'].includes(skill.key)).map((skill) => <option key={skill.id} value={skill.key}>{skill.name}</option>)}
        </select>;
    }
    if (kind === 'spell_technique') {
        return <select className={`${inputClass} mt-3`} value={value.spell_technique || ''} onChange={(event) => onChange({ spell_technique: event.target.value })}>
            <option value="">Spell technique</option>
            {['Potent', 'Far-Reaching', 'Lasting', 'Subtle'].map((name) => <option key={name} value={name}>{name}</option>)}
        </select>;
    }
    if (kind === 'weapon_technique' || kind === 'weapon_or_tactic') {
        return (
            <div className="mt-3 space-y-2">
                <select className={inputClass} value={value.weapon_type_id || ''} onChange={(event) => onChange({ weapon_type_id: event.target.value, talent: '' })}>
                    <option value="">Weapon technique</option>
                    {techniques.map((row) => <option key={row.id} value={row.id}>{row.weapon} · {row.technique}</option>)}
                </select>
                {kind === 'weapon_or_tactic' && <select className={inputClass} value={value.talent || ''} onChange={(event) => onChange({ talent: event.target.value, weapon_type_id: '' })}>
                    <option value="">Or a talent or tactic</option>
                    {talents.map((row) => <option key={row.id} value={row.name}>{row.name}</option>)}
                </select>}
            </div>
        );
    }
    if (kind === 'talent') {
        return <select className={`${inputClass} mt-3`} value={value.talent || ''} onChange={(event) => onChange({ talent: event.target.value })}>
            <option value="">Talent or tactic</option>
            {talents.map((row) => <option key={row.id} value={row.name}>{row.name}</option>)}
        </select>;
    }
    if (kind === 'greater_companion' || kind === 'small_companion') {
        const names = kind === 'greater_companion' ? ['Direwolf', 'Panther', 'Bear'] : ['Raven', 'Owl', 'Eagle', 'Monkey', 'Snake', 'Bat'];
        return (
            <div className="mt-3 space-y-2">
                <select className={inputClass} value={value.companion || ''} onChange={(event) => onChange({ companion: event.target.value })}>
                    <option value="">Companion</option>
                    {names.map((name) => <option key={name} value={name}>{name}</option>)}
                </select>
                {kind === 'small_companion' && <select className={inputClass} value={value.aptitude || ''} onChange={(event) => onChange({ aptitude: event.target.value })}>
                    <option value="">Aptitude</option>
                    {['Awareness', 'Stealth', 'Athletics'].map((name) => <option key={name} value={name}>{name}</option>)}
                </select>}
            </div>
        );
    }
    const meta = spell || {
        count: kind === 'two_spells' ? 2 : kind === 'one_spell' ? 1 : 5,
        max_tier: null,
        choose_order: false,
        orders: orders.length ? orders : null,
    };
    const cap = meta.max_tier == null ? potential : Math.min(potential, meta.max_tier);
    const limit = meta.count || (kind === 'two_spells' ? 2 : kind === 'one_spell' ? 1 : 5);
    const selectedOrder = value.order || (meta.orders?.length === 1 ? meta.orders[0] : '');
    const orderChoices = meta.choose_order ? ['Circle', 'Elemental', 'Forbidden', 'Mythic', 'Nature', 'Necromancy', 'Psionic', 'Runic', 'Shadow', 'Shamanism', 'Spirit'] : [];
    const visible = spells.filter((row) => Number(row.tier) <= cap);
    const picked = value.spells || [];
    return (
        <div className="mt-3 space-y-3">
            {orderChoices.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {orderChoices.map((name) => (
                        <button key={name} type="button" className={selectedOrder === name ? buttonClass : quietClass} onClick={() => onChange({ order: name, spells: [] })}>{name}</button>
                    ))}
                </div>
            )}
            <p className="text-xs text-silver">Level {cap} or lower.{meta.choose_order ? ' Choose an order, then its spells.' : ''}</p>
            <div className="flex flex-wrap gap-2">
                {visible.map((row) => {
                    const allowed = meta.choose_order
                        ? selectedOrder && row.order === selectedOrder
                        : !meta.orders?.length || meta.orders.includes(row.order);
                    const on = picked.map(String).includes(String(row.id));
                    return (
                        <button type="button" key={row.id} aria-disabled={!allowed} className={!allowed ? `${quietClass} cursor-not-allowed opacity-40` : on ? buttonClass : quietClass} onClick={() => {
                            if (!allowed) return;
                            const next = on ? picked.filter((id) => String(id) !== String(row.id)) : (picked.length >= limit ? picked : [...picked, row.id]);
                            onChange({ order: selectedOrder, spells: next });
                        }}>{row.name} · {row.order} {row.tier}</button>
                    );
                })}
            </div>
        </div>
    );
}

function featureDetail(feature) {
    const body = [feature.flavor, feature.rules].filter(Boolean).join('\n\n').replace(/\\(?=\S)/g, '').replaceAll('**', '');
    const choice = choiceDetail(feature.choices);
    return [choice, body].filter(Boolean).join('\n\n');
}

function choiceDetail(choices) {
    if (!choices || typeof choices !== 'object') return '';
    const label = (value) => String(value).replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
    const lines = [];
    if (choices.attribute) lines.push(`Attribute: ${label(choices.attribute)}`);
    if (choices.skill) lines.push(`Skill: ${label(choices.skill)}`);
    if (Array.isArray(choices.skills) && choices.skills.length) lines.push(`Skills: ${choices.skills.map(label).join(', ')}`);
    if (choices.talent) lines.push(`Talent: ${choices.talent}`);
    if (choices.damage_type) lines.push(`${choices.damage_type}`);
    if (choices.primary) lines.push(`Additional feature: ${choices.primary}`);
    return lines.join('\n');
}

const statTips = {
    major: 'Each turn grants one major action. Use it to attack, cast a spell, Dodge, Hinder, grapple, make a major move, or attempt a demanding task. Unused actions expire at the end of your turn.',
    minor: 'Each turn grants two minor actions. Use one to make a short move, stand, draw or stow gear, drink a potion, open a door, command a companion, or activate a tactic. An unused minor action can be reserved for a Response.',
    wounds: 'Remaining health. Damage removes current Wounds. Healing restores them, never above the maximum. At 0 Wounds, you are defeated.',
    suffering: 'Pain and shock, to a maximum of 2. Each level imposes −1 tier on checks, attacks, and saving throws, outside the usual circumstance limit. It does not affect Armor Saves or initiative. A minor action and 1 Stamina removes one level.',
    stamina: 'Spent to remove Suffering, power tactics, and fuel some features. A Minor Rest restores 1. A Full Rest restores it to maximum.',
    luck: 'After a percentile roll is revealed, spend 1 Luck to reverse its tens and ones digits. A roll can be reversed only once, and 100 cannot be reversed. A Full Rest restores Luck to maximum.',
    power: 'Sorcerous Power pays for an Empowered spell and other explicit magical costs. A normal casting costs none unless the spell says otherwise. A Minor Rest restores 1. A Full Rest restores it to maximum.',
    potential: 'Sorcerous Potential is the highest spell level you can learn, to a maximum of 5.',
    soul: 'Sorcerous Soul is how many spells you can know. Each spell occupies one slot. Forgetting one at the end of a Full Rest frees that slot.',
    focus: 'Focus is how many ongoing spells you can maintain at once. Learning your first spell raises it to 1 if it was 0. It becomes at least 2 at character level 4 and at least 3 at character level 7, if you know a spell. Focus is capacity, not a point pool. Maintaining a spell does not spend an action.',
    crit: 'A direct attack critically hits when the roll succeeds and falls in this range. A critical deals +1 wound, or +2 for a two-handed melee attack. Saving-throw attacks cannot critically hit.',
    init: 'Add this bonus to a 2d6 roll at the start of combat. Act from the highest total to the lowest. Ties go to the higher bonus.',
    armor: 'A flat percentile save against a damaging hit. Success on a roll from 01 through this number negates the hit. A worn suit sets this to that armor’s save, and a second suit replaces the first. Suffering does not change an Armor Save.',
    move: 'The first number is feet for a major move. The second is feet for a minor move. Difficult terrain costs twice as much.',
    coin: 'Wealth carried. Ten silver pieces equal one gold piece.',
};

function StatLabel({ icon, label, tip }) {
    return (
        <dt className="text-silver">
            <FeatureTip text={tip}>
                <span className="flex items-center gap-2 underline decoration-line decoration-dotted underline-offset-4">{icon && <img src={icon} alt="" className="h-5 w-5" />} {label}</span>
            </FeatureTip>
        </dt>
    );
}

function FeatureTip({ text, children, align = 'start' }) {
    if (!text) return children;
    const place = align === 'end' ? 'right-0 left-auto' : 'left-0';
    return (
        <span className="group relative inline-block">
            {children}
            <span role="tooltip" className={`absolute top-full z-30 mt-2 hidden max-h-72 w-96 max-w-[calc(100vw-2rem)] overflow-y-auto border border-line bg-ink p-3 text-left text-xs leading-5 font-normal whitespace-pre-wrap text-parchment normal-case shadow-xl group-hover:block ${place}`}>
                {text}
            </span>
        </span>
    );
}

function Resource({ label, value, icon, tip }) {
    if (!value) return null;
    return <div className="flex justify-between"><StatLabel icon={icon} label={label} tip={tip} /><dd>{value.current}/{value.max}</dd></div>;
}

function ResourceEditor({ label, resource, value, onChange, icon, tip }) {
    if (!resource) return null;
    return (
        <div className="flex items-center justify-between gap-2">
            <StatLabel icon={icon} label={label} tip={tip} />
            <dd className="flex items-center gap-1">
                <input className="w-14 border border-line bg-ink px-2 py-1" type="number" min="0" max={resource.max} value={value} onChange={(e) => onChange(Number(e.target.value))} />
                <span>/ {resource.max}</span>
            </dd>
        </div>
    );
}
