import { useEffect, useMemo, useState } from 'react';
import { useForm } from '@inertiajs/react';
import Shell, { Mark, Portrait, buttonClass, inputClass, quietClass } from '../../Components/Shell';

const steps = ['Identity', 'Species', 'Variance', 'Background', 'Class', 'Other Skills', 'Path', 'Gear', 'Finalize'];

export default function Wizard({ species, backgrounds, classes, specializations, features, skills, items, spells, techniques = [] }) {
    const [step, setStep] = useState(0);
    const [specialOpen, setSpecialOpen] = useState(false);
    const form = useForm({
        name: '', gender: '', pronouns: '', height: '', weight: '', age: '', eyes: '', hair: '', biography: '',
        species_id: species[0]?.id || '',
        variance_primary: [],
        variance_secondary: [],
        choices: {},
        background_id: backgrounds[0]?.id || '',
        class_id: classes[0]?.id || '',
        primary_skills: [],
        other_skills: [],
        rogue_skill: '',
        specialization_id: '',
        purchases: [],
        gear_package: '',
        overflow: {},
    });

    const chosenSpecies = species.find((row) => row.id === Number(form.data.species_id));
    const chosenClass = classes.find((row) => row.id === Number(form.data.class_id));
    const primarySkillKeys = (chosenClass?.primary_skills || []).map((skill) => skill.key);
    const chosenBackground = backgrounds.find((row) => row.id === Number(form.data.background_id));
    const primaries = features.filter((row) => row.category === 'creation_primary');
    const secondaries = features.filter((row) => row.category === 'creation_secondary');
    const talents = features
        .filter((row) => row.category === 'talent' || row.category === 'tactic')
        .sort((a, b) => (a.category === b.category ? a.name.localeCompare(b.name) : a.category === 'tactic' ? 1 : -1));
    const nonattack = skills.filter((row) => !row.is_attack_skill);
    const speciesFeatures = [
        ...(chosenSpecies?.traits_json?.primary || []),
        ...(chosenSpecies?.traits_json?.secondary || []),
    ];
    const grantedNames = new Set([
        ...speciesFeatures,
        ...form.data.variance_primary,
        ...form.data.variance_secondary,
        chosenBackground?.grants_json?.feature,
        (chosenClass?.progression_json || []).find((row) => Number(row.level) === 1)?.feature,
        form.data.choices?.Accursed?.primary,
    ].filter(Boolean).map(normalizeFeature));
    if (form.data.gear_package === 'spear') {
        grantedNames.add('shield');
    }
    const owned = [
        ...speciesFeatures,
        ...form.data.variance_primary,
        ...form.data.variance_secondary,
    ];
    const gearPackages = useMemo(() => {
        const defined = [
            { key: 'spear', label: 'Light armor, spear, and shield.', items: ['Light armor', 'Spear', 'Shield'] },
            { key: 'archer', label: 'Light armor, dagger, bow, and 20 arrows.', items: ['Light armor', 'Dagger', 'Bow', 'Arrows, 20'] },
            { key: 'robes', label: 'Robes, staff, and a healing draught.', items: ['Robes', 'Staff', 'Healing draught'] },
            { key: 'sword', label: 'Medium armor and a sword.', items: ['Medium armor', 'Sword'] },
        ];
        const costOf = (names) => names.reduce((sum, name) => sum + (items.find((item) => item.name.toLowerCase() === name.toLowerCase())?.cost_silver || 0), 0);
        const priced = defined.map((pack) => ({ ...pack, cost: costOf(pack.items) }));
        const purse = Math.max(...priced.map((pack) => pack.cost));
        return [
            ...priced.map((pack) => ({ ...pack, bonus: purse - pack.cost })),
            { key: 'cash', label: 'Buy your own starting gear.', items: [], cost: purse, bonus: purse },
        ];
    }, [items]);

    function toggle(list, value, limit) {
        const has = list.includes(value);
        if (has) return list.filter((item) => item !== value);
        if (list.length >= limit) return list;
        return [...list, value];
    }

    const featureCatalog = useMemo(() => {
        const catalog = new Map();
        features.forEach((feature) => {
            const key = normalizeFeature(feature.name);
            catalog.set(`${feature.category}:${key}`, feature);
            if (!catalog.has(key) || String(feature.category).startsWith('creation_')) {
                catalog.set(key, feature);
            }
        });
        return catalog;
    }, [features]);
    const speciesGranted = useMemo(() => new Set(speciesFeatures.map(normalizeFeature)), [speciesFeatures]);

    function toggleVariance(key, name, limit) {
        const current = form.data[key];
        const other = key === 'variance_primary' ? form.data.variance_secondary : form.data.variance_primary;
        if (current.includes(name)) {
            form.setData(key, retainQualified(current.filter((item) => item !== name), other, speciesGranted, featureCatalog));
            return;
        }
        const selected = [...current, ...other];
        if (current.length >= limit || !featureQualifies(name, selected, speciesGranted, featureCatalog)) return;
        form.setData(key, retainQualified([...current, name], other, speciesGranted, featureCatalog));
        if (opensSpecial(name, featureCatalog)) setSpecialOpen(true);
    }

    function continueStep() {
        setSpecialOpen(false);
        setStep((current) => Math.min(steps.length - 1, current + 1));
        window.scrollTo({ top: 0, left: 0, behavior: 'auto' });
    }

    function chooseClass(row) {
        const primary = (row.primary_skills || []).map((skill) => skill.key);
        form.setData({
            ...form.data,
            class_id: row.id,
            primary_skills: primary,
            other_skills: form.data.other_skills.filter((key) => !primary.includes(key)),
        });
        const levelOne = (row.progression_json || []).find((level) => Number(level.level) === 1);
        const levelFeature = featureCatalog.get(normalizeFeature(levelOne?.feature));
        if (row.role?.name === 'Rogue' || opensSpecial(levelOne?.feature, featureCatalog)) setSpecialOpen(true);
    }

    function chooseSpecies(row) {
        const granted = new Set([...(row.traits_json?.primary || []), ...(row.traits_json?.secondary || [])].map(normalizeFeature));
        const primary = retainQualified(form.data.variance_primary, form.data.variance_secondary, granted, featureCatalog);
        const secondary = retainQualified(form.data.variance_secondary, primary, granted, featureCatalog);
        form.setData({
            ...form.data,
            species_id: row.id,
            variance_primary: primary,
            variance_secondary: secondary,
        });
        const traits = [...(row.traits_json?.primary || []), ...(row.traits_json?.secondary || [])];
        if (traits.some((name) => opensSpecial(name, featureCatalog))) setSpecialOpen(true);
    }

    function setChoice(feature, patch) {
        form.setData('choices', { ...form.data.choices, [feature]: { ...(form.data.choices[feature] || {}), ...patch } });
    }

    const spellPotential = chosenClass?.role?.name === 'Magus' ? 1 : 0;
    const optionContext = {
        speciesFeatures,
        owned,
        backgroundFeature: chosenBackground?.grants_json?.feature,
        classRole: chosenClass?.role?.name,
        classFeature: (chosenClass?.progression_json || []).find((level) => Number(level.level) === 1)?.feature,
        specializationFeature: (specializations.find((row) => row.id === form.data.specialization_id)?.progression_json || []).find((level) => Number(level.level) === 1)?.feature,
        featureCatalog,
        choices: form.data.choices,
    };
    const specialOptions = (step === steps.length - 1 ? [1, 2, 3, 4, 6] : [step])
        .flatMap((index) => specialOptionsForStep(index, optionContext))
        .filter((option, index, list) => list.findIndex((row) => row.key === option.key) === index);
    const chosenGear = gearPackages.find((pack) => pack.key === form.data.gear_package);

    useEffect(() => {
        if (!specialOpen) return undefined;
        function onKey(event) {
            if (event.key === 'Escape') setSpecialOpen(false);
        }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [specialOpen]);

    useEffect(() => {
        if (specialOptions.length === 0) setSpecialOpen(false);
    }, [specialOptions.length]);

    return (
        <Shell title="Character Forge">
            <div className="mb-6 flex flex-wrap gap-2">
                {steps.map((label, index) => (
                    <button key={label} type="button" className={index === step ? buttonClass : quietClass} onClick={() => { setSpecialOpen(false); setStep(index); }}>{index + 1}. {label}</button>
                ))}
            </div>
            {step === 0 && (
                <div className="grid gap-4 md:grid-cols-2">
                    {['name', 'gender', 'pronouns', 'height', 'weight', 'age', 'eyes', 'hair'].map((field) => (
                        <label key={field} className="text-sm capitalize text-silver">{field}
                            <input className={`${inputClass} mt-1`} value={form.data[field]} onChange={(e) => form.setData(field, e.target.value)} />
                        </label>
                    ))}
                    <label className="text-sm text-silver md:col-span-2">Biography
                        <textarea className={`${inputClass} mt-1`} rows={4} value={form.data.biography} onChange={(e) => form.setData('biography', e.target.value)} />
                    </label>
                </div>
            )}
            {step === 1 && (
                <div className="grid gap-3 md:grid-cols-2">
                    {species.map((row) => (
                        <button type="button" key={row.id} onClick={() => chooseSpecies(row)} className={`flex gap-3 border p-4 text-left ${form.data.species_id === row.id ? 'border-maroon bg-maroon-deep/30' : 'border-line bg-panel'}`}>
                            <Portrait src={row.portrait_url} name={row.name} />
                            <span>
                                <span className="block font-display text-2xl">{row.name}</span>
                                <span className="text-sm text-parchment-dim">{row.description}</span>
                                <span className="mt-2 block text-xs text-silver">
                                    {[...(row.traits_json?.primary || []), ...(row.traits_json?.secondary || [])].map((name, index, list) => (
                                        <span key={name}>
                                            <FeatureLabel name={name} catalog={featureCatalog} />
                                            {index < list.length - 1 ? ' · ' : ''}
                                        </span>
                                    ))}
                                </span>
                            </span>
                        </button>
                    ))}
                </div>
            )}
            {step === 2 && (
                <div className="space-y-6">
                    <p className="text-sm text-silver">Choose two more Primary features and one Secondary feature. A requirement must come from your species, or from another pick you already qualify for, before the next feature in that chain opens.</p>
                    <FeaturePick title="Primary" rows={primaries} selected={form.data.variance_primary} owned={owned} speciesFeatures={speciesFeatures} selectedAll={[...form.data.variance_primary, ...form.data.variance_secondary]} catalog={featureCatalog} onToggle={(name) => toggleVariance('variance_primary', name, 2)} />
                    <FeaturePick title="Secondary" rows={secondaries} selected={form.data.variance_secondary} owned={owned} speciesFeatures={speciesFeatures} selectedAll={[...form.data.variance_primary, ...form.data.variance_secondary]} catalog={featureCatalog} onToggle={(name) => toggleVariance('variance_secondary', name, 1)} />
                </div>
            )}
            {step === 3 && (
                <div className="grid gap-3">
                    {backgrounds.map((row) => (
                        <div key={row.id} className={`border p-4 ${form.data.background_id === row.id ? 'border-maroon' : 'border-line bg-panel'}`}>
                            <button type="button" onClick={() => { form.setData('background_id', row.id); if (opensSpecial(row.grants_json?.feature, featureCatalog)) setSpecialOpen(true); }} className="w-full text-left">
                                <span className="font-display text-2xl">{row.name}</span>
                                <span className="mt-1 block text-sm text-parchment-dim">{row.description}</span>
                            </button>
                            <div className="mt-3 flex flex-wrap gap-x-3 gap-y-2 text-xs text-silver">
                                {(row.grants_json?.attributes || []).map((name) => (
                                    <RuleTooltip key={`${row.id}-attr-${name}`} text={attributeBoostDetail(name)}>
                                        <span className="underline decoration-line decoration-dotted underline-offset-2">{name.replace(/\s*\+\d+/, '')} +1</span>
                                    </RuleTooltip>
                                ))}
                                {(row.grants_json?.skills || []).map((name) => (
                                    <RuleTooltip key={`${row.id}-skill-${name}`} text={skillTrainingDetail(name, skills)}>
                                        <span className="underline decoration-line decoration-dotted underline-offset-2">{name} +1</span>
                                    </RuleTooltip>
                                ))}
                            </div>
                            {row.grants_json?.feature && (
                                <div className="mt-3">
                                    <p className="text-xs tracking-widest text-silver uppercase">Background Feature</p>
                                    <div className="mt-1 text-xs tracking-wide text-maroon uppercase">
                                        <FeatureLabel name={row.grants_json.feature} catalog={featureCatalog} category="background" />
                                    </div>
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            )}
            {step === 4 && (
                <div className="space-y-4">
                    <div className="grid gap-3 md:grid-cols-2">
                        {classes.map((row) => (
                            <div key={row.id} className={`border p-4 ${form.data.class_id === row.id ? 'border-maroon' : 'border-line bg-panel'}`}>
                                <button type="button" onClick={() => chooseClass(row)} className="w-full text-left">
                                    <span className="block font-display text-2xl">{row.name}</span>
                                    {row.role?.name && (
                                        <RuleTooltip block text={roleTooltip(row.role)}>
                                            <span className="mt-3 block text-xs tracking-widest text-maroon uppercase underline decoration-line decoration-dotted underline-offset-2">{row.role.name === 'Magus' ? 'Spellcaster' : row.role.name}</span>
                                        </RuleTooltip>
                                    )}
                                    <span className="mt-2 block text-sm text-parchment-dim">{row.description}</span>
                                </button>
                                {(row.primary_skills || []).length > 0 && (
                                    <div className="mt-3">
                                        <p className="text-xs tracking-widest text-silver uppercase">Bonuses to all primary skills</p>
                                        <div className="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-silver">
                                            {(row.primary_skills || []).map((skill) => (
                                                <RuleTooltip key={`${row.id}-primary-${skill.id}`} text={primarySkillDetail(skill)}>
                                                    <span className="underline decoration-line decoration-dotted underline-offset-2">{skill.name} +2</span>
                                                </RuleTooltip>
                                            ))}
                                        </div>
                                    </div>
                                )}
                                <div className="mt-3">
                                    <p className="text-xs tracking-widest text-silver uppercase">Level Based Features</p>
                                    <div className="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-silver">
                                        {(row.progression_json || []).map((level) => (
                                            <FeatureLabel key={`${row.id}-${level.level}-${level.feature}`} name={level.feature} catalog={featureCatalog} category="class" prefix={`Level ${level.level}: `} />
                                        ))}
                                    </div>
                                </div>
                                {row.note && <p className="mt-2 text-xs text-parchment-dim">{row.note}</p>}
                            </div>
                        ))}
                    </div>
                </div>
            )}
            {step === 5 && (
                <div className="space-y-4">
                    <SkillPick title="Three other skills, +1" skills={skills} selected={form.data.other_skills} blocked={primarySkillKeys} limit={3} onToggle={(key) => form.setData('other_skills', toggle(form.data.other_skills.filter((skill) => !primarySkillKeys.includes(skill)), key, 3))} />
                </div>
            )}
            {step === 6 && (
                <div className="grid gap-3 md:grid-cols-2">
                    <button type="button" onClick={() => form.setData('specialization_id', '')} className={`border p-4 text-left ${form.data.specialization_id === '' ? 'border-maroon' : 'border-line'}`}>No specialization</button>
                    {specializations.map((row) => (
                        <div key={row.id} className={`border p-4 ${form.data.specialization_id === row.id ? 'border-maroon' : 'border-line bg-panel'}`}>
                            <button type="button" onClick={() => { form.setData('specialization_id', row.id); const feature = (row.progression_json || []).find((level) => Number(level.level) === 1)?.feature; if (opensSpecial(feature, featureCatalog)) setSpecialOpen(true); }} className="w-full text-left">
                                <span className="font-display text-2xl">{row.name}</span>
                                <span className="mt-2 block text-sm text-parchment-dim">{row.description}</span>
                            </button>
                            <div className="mt-3 flex flex-wrap gap-x-3 gap-y-1 text-xs text-silver">
                                {(row.progression_json || []).map((level) => (
                                    <FeatureLabel key={`${row.id}-${level.level}-${level.feature}`} name={level.feature} catalog={featureCatalog} category="class" prefix={`Level ${level.level}: `} />
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
            )}
            {step === 8 && (
                <div className="space-y-6">
                    <p className="text-sm text-silver">Attributes may rise past 10. Special options can be left unset.</p>
                    <dl className="grid gap-4 md:grid-cols-2">
                        <Summary label="Name" value={form.data.name || 'Unnamed'} />
                        <Summary label="Species" value={chosenSpecies?.name} />
                        <Summary label="Variance" value={[...form.data.variance_primary, ...form.data.variance_secondary].join(', ')} />
                        <Summary label="Background" value={chosenBackground?.name} />
                        <Summary label="Class" value={chosenClass?.name} />
                        <Summary label="Other skills" value={form.data.other_skills.map((key) => skills.find((skill) => skill.key === key)?.name || key).join(', ')} />
                        <Summary label="Path" value={specializations.find((row) => row.id === form.data.specialization_id)?.name || 'No specialization'} />
                        <Summary label="Gear" value={chosenGear ? (chosenGear.key === 'cash' ? 'Buy your own starting gear' : chosenGear.label) : ''} />
                    </dl>
                    {specialOptions.length > 0 && (
                        <section>
                            <h2 className="font-display text-2xl">Special options</h2>
                            <ul className="mt-2 space-y-2 text-sm">
                                {specialOptions.map((option) => {
                                    const chosen = choiceSummary(option, form.data.choices, form.data.rogue_skill, skills);
                                    return (
                                        <li key={option.key} className="border border-line bg-panel p-3">
                                            <span className="font-display text-xl">{option.title}</span>
                                            <span className={`mt-1 block ${chosen ? 'text-parchment' : 'text-silver'}`}>{chosen || 'Not chosen'}</span>
                                        </li>
                                    );
                                })}
                            </ul>
                        </section>
                    )}
                </div>
            )}
            {step === 7 && (
                <div>
                    <p className="mb-4 text-sm text-silver">Starting purse: 10 gold, 0 silver. The common kit and background gear are already packed.</p>
                    <p className="mb-4 text-sm text-parchment-dim">Common kit: backpack, sack, 3 torches, 3 rations, travel clothing, and a full waterskin.{chosenBackground?.grants_json?.equipment?.length ? ` Background gear: ${chosenBackground.grants_json.equipment.join(', ')}.` : ''}</p>
                    <div className="grid gap-3">
                        {gearPackages.map((pack) => (
                            <button key={pack.key} type="button" onClick={() => form.setData('gear_package', pack.key)} className={`border p-4 text-left ${form.data.gear_package === pack.key ? 'border-maroon bg-maroon-deep/30' : 'border-line bg-panel'}`}>
                                <span className="font-display text-2xl">{pack.key === 'cash' ? coins(pack.cost) : pack.label}</span>
                                {pack.key === 'cash' && <span className="mt-2 block text-sm text-parchment-dim">Take the value of the richest package and buy your own gear.</span>}
                                {pack.bonus > 0 && pack.key !== 'cash' && <span className="mt-2 block text-sm text-silver">Bonus starting gold: {coins(pack.bonus)}.</span>}
                                <span className="mt-1 block text-sm text-silver">You begin with {coins(100 + pack.bonus)}.</span>
                            </button>
                        ))}
                    </div>
                </div>
            )}
            <div className="mt-8 flex items-center justify-between gap-3">
                <button type="button" className={quietClass} onClick={() => { setSpecialOpen(false); setStep(Math.max(0, step - 1)); }}>Back</button>
                <div className="flex gap-2">
                    {specialOptions.length > 0 && (
                        <button type="button" className={buttonClass} onClick={() => setSpecialOpen(true)}>Special Option</button>
                    )}
                    {step < steps.length - 1
                        ? <button type="button" className={buttonClass} onClick={continueStep}>Continue</button>
                        : <button type="button" className={buttonClass} disabled={form.processing} onClick={() => form.post('/characters')}>Create character</button>}
                </div>
            </div>
            {specialOpen && (
                <SpecialOptionModal
                    options={specialOptions}
                    choices={form.data.choices}
                    setChoice={setChoice}
                    skills={nonattack}
                    talents={talents}
                    grantedNames={grantedNames}
                    spells={spells}
                    techniques={techniques}
                    potential={spellPotential}
                    primaries={primaries}
                    owned={owned}
                    selectedAll={[...form.data.variance_primary, ...form.data.variance_secondary]}
                    speciesFeatures={speciesFeatures}
                    catalog={featureCatalog}
                    rogueSkill={form.data.rogue_skill}
                    setRogueSkill={(skill) => form.setData('rogue_skill', skill)}
                    onClose={() => setSpecialOpen(false)}
                />
            )}
            {Object.keys(form.errors).length > 0 && (
                <ul className="mt-4 text-sm text-maroon">{Object.values(form.errors).map((error) => <li key={error}>{error}</li>)}</ul>
            )}
        </Shell>
    );
}

function normalizeFeature(name) {
    return name.replace(/\.$/, '').trim().toLowerCase();
}

function featureQualifies(name, selected, speciesGranted, catalog, seen = new Set()) {
    const prerequisite = catalog.get(normalizeFeature(name))?.prerequisites_json?.[0];
    if (!prerequisite) return true;
    const needed = normalizeFeature(prerequisite);
    if (speciesGranted.has(needed)) return true;
    if (seen.has(needed)) return false;
    const pick = selected.find((item) => normalizeFeature(item) === needed);
    if (!pick) return false;
    seen.add(needed);
    return featureQualifies(pick, selected, speciesGranted, catalog, seen);
}

function coins(silver) {
    const gold = Math.floor(silver / 10);
    const rest = silver % 10;
    if (gold && rest) return `${gold} gold, ${rest} silver`;
    if (gold) return `${gold} gold`;
    return `${rest} silver`;
}

function retainQualified(list, otherSelected, speciesGranted, catalog) {
    const pool = [...list];
    const kept = [];
    let moved = true;
    while (moved) {
        moved = false;
        for (let index = 0; index < pool.length; index += 1) {
            const name = pool[index];
            if (featureQualifies(name, [...kept, ...otherSelected], speciesGranted, catalog)) {
                kept.push(name);
                pool.splice(index, 1);
                moved = true;
                break;
            }
        }
    }
    return kept;
}

function FeaturePick({ title, rows, selected, owned, speciesFeatures, selectedAll, catalog, onToggle }) {
    const granted = speciesFeatures.map(normalizeFeature);
    return (
        <section>
            <h2 className="font-display text-2xl">{title}</h2>
            <div className="mt-2 grid gap-2 md:grid-cols-2">
                {rows.map((row) => {
                    const taken = owned.includes(row.name) && !selected.includes(row.name);
                    const prerequisite = row.prerequisites_json?.[0];
                    const met = prerequisite && featureQualifies(row.name, selectedAll, new Set(granted), catalog);
                    const locked = taken || (!selected.includes(row.name) && prerequisite && !met);
                    return (
                        <RuleTooltip key={row.id} block text={ruleDetail(row)}>
                            <button type="button" aria-disabled={locked} onClick={() => { if (!locked) onToggle(row.name); }} className={`w-full border p-3 text-left ${selected.includes(row.name) ? 'border-maroon' : 'border-line'} ${locked ? 'cursor-not-allowed opacity-40' : ''}`}>
                                <span className="font-display text-xl">{row.name}</span>
                                <span className="block text-xs text-silver">{row.flavor_text}</span>
                                {prerequisite && <span className={`text-xs ${met ? 'text-silver-green' : 'text-maroon'}`}>Requires {prerequisite}</span>}
                            </button>
                        </RuleTooltip>
                    );
                })}
            </div>
        </section>
    );
}

const CHOICE_SPECS = {
    'attribute focus': { kind: 'attribute', title: 'Attribute Focus', key: 'Attribute Focus', detail: 'Choose one attribute. It increases by 1 step.' },
    skilled: { kind: 'nonattack', title: 'Skilled', key: 'Skilled', detail: 'Choose one nonattack skill. It increases by 2 ranks.' },
    versatile: { kind: 'nonattack_two', title: 'Versatile', key: 'Versatile', detail: 'Choose two different nonattack skills. Each increases by 1 rank.' },
    talented: { kind: 'talent', title: 'Talented', key: 'Talented', detail: 'Choose one talent.' },
    'elemental resistance': { kind: 'damage_type', title: 'Elemental Resistance', key: 'Elemental Resistance', detail: 'Choose Fire, Storm, or Cold.' },
    accursed: { kind: 'accursed', title: 'Accursed', key: 'Accursed', detail: 'Choose Fire, Storm, or Cold, and one additional Primary feature you do not already have.' },
    magus: { kind: 'two_spells', title: 'Magus', key: 'Magus', detail: 'Learn two tier-0 spells.' },
    rogue: { kind: 'rogue', title: 'Rogue', key: 'Rogue', detail: 'Gain +1 rank in one nonattack skill.' },
};

function Summary({ label, value }) {
    return (
        <div className="border border-line bg-panel p-4">
            <dt className="text-xs tracking-widest text-silver uppercase">{label}</dt>
            <dd className="mt-1 font-display text-2xl">{value || 'Not chosen'}</dd>
        </div>
    );
}

function choiceSummary(option, choices, rogueSkill, skills) {
    if (option.kind === 'attribute') return ATTRIBUTES.find((row) => row.key === choices['Attribute Focus']?.attribute)?.name || '';
    if (option.kind === 'nonattack') return skills.find((skill) => skill.key === choices.Skilled?.skill)?.name || '';
    if (option.kind === 'nonattack_two') return (choices.Versatile?.skills || []).map((key) => skills.find((skill) => skill.key === key)?.name || key).join(', ');
    if (option.kind === 'talent') return choices.Talented?.talent || '';
    if (option.kind === 'damage_type') return choices['Elemental Resistance']?.damage_type || '';
    if (option.kind === 'accursed') return [choices.Accursed?.damage_type, choices.Accursed?.primary].filter(Boolean).join(', ');
    if (option.kind === 'two_spells') return [choices.Magus?.order, (choices.Magus?.spells || []).length ? `${choices.Magus.spells.length} spells` : ''].filter(Boolean).join(', ');
    if (option.kind === 'rogue') return skills.find((skill) => skill.key === rogueSkill)?.name || '';
    if (option.kind === 'order') return choices[option.key]?.order || '';
    if (option.kind === 'learn_spells' || option.kind === 'weapon_technique') {
        const current = choices[option.key] || {};
        return [current.order, (current.spells || []).length ? `${current.spells.length} spells` : '', current.weapon_type_id ? 'Weapon technique' : ''].filter(Boolean).join(', ');
    }
    return '';
}

function choiceSpec(name) {
    if (!name) return null;
    return CHOICE_SPECS[normalizeFeature(name)] || null;
}

function specialOptionsForStep(step, { speciesFeatures, owned, backgroundFeature, classRole, classFeature, specializationFeature, featureCatalog, choices }) {
    const names = step === 1 ? speciesFeatures
        : step === 2 ? owned
            : step === 3 ? [backgroundFeature]
                : step === 4 ? [classFeature]
                    : step === 6 ? [specializationFeature]
                        : [];
    const options = [];
    const push = (spec) => {
        if (spec && !options.some((row) => row.key === spec.key)) options.push(spec);
    };
    names.filter(Boolean).forEach((name) => {
        push(choiceSpec(name));
        push(spellOption(name, featureCatalog));
        push(orderOption(name, featureCatalog));
        push(techniqueOption(name, featureCatalog));
        if (normalizeFeature(name) === 'accursed') {
            const extra = choices.Accursed?.primary;
            push(choiceSpec(extra));
            push(spellOption(extra, featureCatalog));
            push(orderOption(extra, featureCatalog));
            push(techniqueOption(extra, featureCatalog));
            if (extra && normalizeFeature(extra) === 'talented') {
                push(choiceSpec(choices.Talented?.talent));
                push(spellOption(choices.Talented?.talent, featureCatalog));
                push(orderOption(choices.Talented?.talent, featureCatalog));
                push(techniqueOption(choices.Talented?.talent, featureCatalog));
            }
        }
        if (normalizeFeature(name) === 'talented') {
            push(choiceSpec(choices.Talented?.talent));
            push(spellOption(choices.Talented?.talent, featureCatalog));
            push(orderOption(choices.Talented?.talent, featureCatalog));
            push(techniqueOption(choices.Talented?.talent, featureCatalog));
        }
    });
    if (step === 4 && classRole === 'Rogue') push(choiceSpec('Rogue'));
    return options;
}

const SPELL_ORDERS = ['Circle', 'Elemental', 'Forbidden', 'Mythic', 'Nature', 'Necromancy', 'Psionic', 'Runic', 'Shadow', 'Shamanism', 'Spirit'];

function spellGrantFromRules(text) {
    if (!text || /learn two tier[ -]?0 spells/i.test(text)) return null;
    const countMatch = text.match(/learn up to (one|two|three|four|five|\d+) /i);
    if (!countMatch) return null;
    const words = { one: 1, two: 2, three: 3, four: 4, five: 5 };
    const count = words[countMatch[1].toLowerCase()] ?? Number(countMatch[1]);
    const tierMatch = text.match(/(?:level|tier)[ -]?(\d+)\s+spells/i);
    const maxTier = tierMatch ? Number(tierMatch[1]) : null;
    const orderMatch = text.match(/learn up to (?:one|two|three|four|five|\d+) ([A-Za-z]+) spells/i);
    const order = orderMatch ? SPELL_ORDERS.find((name) => name.toLowerCase() === orderMatch[1].toLowerCase()) : null;
    if (order) return { count, orders: [order], chooseOrder: false, allOrders: false, maxTier };
    if (/spells from (?:any|every)/i.test(text) || /access to every order/i.test(text)) return { count, orders: null, chooseOrder: false, allOrders: true, maxTier };
    if (/choose one order/i.test(text) || /one order of magic and learn up to/i.test(text) || /spells from (?:that order|it)\b/i.test(text)) return { count, orders: null, chooseOrder: true, allOrders: false, maxTier };
    return null;
}

function grantsOrderChoice(text) {
    if (!text) return false;
    if (/gain access to one order of magic|choose one order/i.test(text)) return true;
    return Boolean(spellGrantFromRules(text)?.chooseOrder);
}

function opensSpecial(name, catalog) {
    const feature = catalog?.get(normalizeFeature(name));
    return Boolean(choiceSpec(name) || spellGrantFromRules(feature?.rules_text) || grantsWeaponTechnique(name, feature) || grantsOrderChoice(feature?.rules_text));
}

function orderOption(name, catalog) {
    if (!name || choiceSpec(name)?.kind === 'two_spells') return null;
    const feature = catalog?.get(normalizeFeature(name));
    if (!feature || spellGrantFromRules(feature.rules_text)?.chooseOrder) return null;
    if (!/gain access to one order of magic|choose one order/i.test(feature.rules_text || '')) return null;
    return { kind: 'order', title: feature.name, key: feature.name, detail: 'Choose one order of magic.' };
}

function spellOption(name, catalog) {
    if (!name) return null;
    const feature = catalog?.get(normalizeFeature(name));
    const grant = spellGrantFromRules(feature?.rules_text);
    if (!grant) return null;
    const where = grant.chooseOrder ? 'from one order you choose' : grant.allOrders ? 'from any order' : `from ${grant.orders.join(' or ')}`;
    return {
        kind: 'learn_spells',
        title: feature.name,
        key: feature.name,
        detail: `Learn up to ${grant.count} ${grant.count === 1 ? 'spell' : 'spells'} ${where}.`,
        grant,
        technique: grantsWeaponTechnique(name, feature),
    };
}

function grantsWeaponTechnique(name, feature) {
    if (/weapon technique/i.test(String(name || ''))) return true;
    return /learn one weapon technique/i.test(feature?.rules_text || '');
}

function techniqueOption(name, catalog) {
    if (!name) return null;
    const feature = catalog?.get(normalizeFeature(name));
    if (spellGrantFromRules(feature?.rules_text)) return null;
    if (!grantsWeaponTechnique(name, feature)) return null;
    const title = feature?.name || name;
    return { kind: 'weapon_technique', title, key: title, detail: 'Learn one Weapon Technique for a weapon type that lists it.' };
}

function SpecialOptionModal({ options, choices, setChoice, skills, talents, spells, techniques = [], potential, primaries, owned, selectedAll, speciesFeatures, catalog, grantedNames, rogueSkill, setRogueSkill, onClose }) {
    return (
        <div className="fixed inset-0 z-[70] flex items-center justify-center bg-black/70 p-4" onClick={onClose}>
            <div role="dialog" aria-modal="true" aria-labelledby="special-option-title" className="flex max-h-[85vh] w-full max-w-2xl flex-col overflow-hidden border border-line bg-panel p-6 shadow-xl md:min-h-[70vh] md:w-[45vw] md:max-w-[45vw] md:shrink-0" onClick={(event) => event.stopPropagation()}>
                <div className="flex items-start justify-between gap-4">
                    <h2 id="special-option-title" className="font-display text-3xl">Special Option</h2>
                    <button type="button" className={quietClass} onClick={onClose}>Close</button>
                </div>
                <div className="mt-5 min-h-0 flex-1 space-y-6 overflow-y-auto">
                    {options.map((option) => (
                        <section key={option.key}>
                            <h3 className="font-display text-2xl">{option.title}</h3>
                            <p className="mt-1 text-sm text-silver">{option.detail}</p>
                            <div className="mt-3">
                                <SpecialChoice option={option} choices={choices} setChoice={setChoice} skills={skills} talents={talents} spells={spells} techniques={techniques} potential={potential} primaries={primaries} owned={owned} selectedAll={selectedAll} speciesFeatures={speciesFeatures} catalog={catalog} grantedNames={grantedNames} rogueSkill={rogueSkill} setRogueSkill={setRogueSkill} />
                            </div>
                        </section>
                    ))}
                </div>
                <div className="mt-6 flex justify-end">
                    <button type="button" className={buttonClass} onClick={onClose}>OK</button>
                </div>
            </div>
        </div>
    );
}

function SpecialChoice({ option, choices, setChoice, skills, talents, spells, techniques = [], potential = 0, primaries, owned, selectedAll, speciesFeatures, catalog, grantedNames, rogueSkill, setRogueSkill }) {
    if (option.kind === 'attribute') {
        return <AttributeFocusChoice value={choices['Attribute Focus']?.attribute || ''} onChange={(attribute) => setChoice('Attribute Focus', { attribute })} />;
    }
    if (option.kind === 'nonattack') {
        return <ChoiceButtons rows={skills} selected={choices.Skilled?.skill || ''} tip={(skill) => skillChoiceDetail(skill, 2)} onPick={(key) => setChoice('Skilled', { skill: key })} />;
    }
    if (option.kind === 'nonattack_two') {
        const picked = choices.Versatile?.skills || [];
        return <ChoiceButtons rows={skills} selected={picked} limit={2} tip={(skill) => skillChoiceDetail(skill, 1)} onPick={(key) => {
            const next = picked.includes(key) ? picked.filter((item) => item !== key) : picked.length >= 2 ? picked : [...picked, key];
            setChoice('Versatile', { skills: next });
        }} />;
    }
    if (option.kind === 'talent') {
        return <ChoiceButtons wide rows={talents.map((talent) => ({ id: talent.id, key: talent.name, name: talent.name, detail: ruleDetail(talent), locked: !talentRequirementsMet(talent, grantedNames) }))} selected={choices.Talented?.talent || ''} onPick={(name) => setChoice('Talented', { talent: name })} />;
    }
    if (option.kind === 'damage_type') {
        return <ChoiceButtons rows={DAMAGE_TYPES} selected={choices['Elemental Resistance']?.damage_type || ''} tip={(type) => damageTypeDetail(type.name, 'resistance')} onPick={(name) => setChoice('Elemental Resistance', { damage_type: name })} />;
    }
    if (option.kind === 'accursed') {
        const granted = new Set(speciesFeatures.map(normalizeFeature));
        return (
            <div className="space-y-3">
                <ChoiceButtons rows={DAMAGE_TYPES} selected={choices.Accursed?.damage_type || ''} tip={(type) => damageTypeDetail(type.name, 'weakness')} onPick={(name) => setChoice('Accursed', { damage_type: name })} />
                <div className="flex flex-wrap gap-2">
                    {primaries.filter((row) => !owned.includes(row.name)).map((row) => {
                        const locked = !featureQualifies(row.name, selectedAll, granted, catalog);
                        const picked = choices.Accursed?.primary === row.name;
                        return (
                            <RuleTooltip key={row.id} text={ruleDetail(row)} wide above>
                                <button type="button" aria-disabled={locked && !picked} onClick={() => { if (!(locked && !picked)) setChoice('Accursed', { primary: row.name }); }} className={locked && !picked ? `${quietClass} cursor-not-allowed opacity-40` : picked ? buttonClass : quietClass}>{row.name}</button>
                            </RuleTooltip>
                        );
                    })}
                </div>
            </div>
        );
    }
    if (option.kind === 'two_spells') {
        const order = choices.Magus?.order || '';
        return (
            <div className="space-y-3">
                <div className="flex flex-wrap gap-2">
                    {SPELL_ORDERS.map((name) => (
                        <button key={name} type="button" className={order === name ? buttonClass : quietClass} onClick={() => setChoice('Magus', { order: name, spells: [] })}>{name}</button>
                    ))}
                </div>
                <SpellPick spells={spells.filter((spell) => Number(spell.tier) === 0)} order={order} value={choices.Magus?.spells || []} onChange={(ids) => setChoice('Magus', { order, spells: ids })} />
            </div>
        );
    }
    if (option.kind === 'order') {
        const current = choices[option.key]?.order || '';
        return (
            <div className="flex flex-wrap gap-2">
                {SPELL_ORDERS.map((name) => (
                    <button key={name} type="button" className={current === name ? buttonClass : quietClass} onClick={() => setChoice(option.key, { order: name })}>{name}</button>
                ))}
            </div>
        );
    }
    if (option.kind === 'learn_spells') {
        const current = choices[option.key] || {};
        return (
            <div className="space-y-4">
                <SpellGrantPick grant={option.grant} spells={spells} potential={potential} value={current} onChange={(next) => setChoice(option.key, next)} />
                {option.technique && <TechniquePick techniques={techniques} selected={current.weapon_type_id} onPick={(id) => setChoice(option.key, { weapon_type_id: id })} />}
            </div>
        );
    }
    if (option.kind === 'weapon_technique') {
        const current = choices[option.key] || {};
        return <TechniquePick techniques={techniques} selected={current.weapon_type_id} onPick={(id) => setChoice(option.key, { weapon_type_id: id })} />;
    }
    if (option.kind === 'rogue') {
        return <ChoiceButtons rows={skills} selected={rogueSkill} tip={(skill) => skillChoiceDetail(skill, 1)} onPick={setRogueSkill} />;
    }
    return null;
}

function ChoiceButtons({ rows, selected, onPick, limit, tip, wide = false }) {
    const many = Array.isArray(selected);
    return (
        <div className="flex flex-wrap gap-2">
            {rows.map((row) => {
                const key = row.key || row.name;
                const picked = many ? selected.includes(key) : selected === key;
                const locked = Boolean(row.locked);
                const full = many && limit && selected.length >= limit && !picked;
                const blocked = full || (locked && !picked);
                const text = row.detail || (tip ? tip(row) : '');
                return (
                    <RuleTooltip key={row.id || key} text={text} wide={wide} above>
                        <button type="button" aria-disabled={blocked} onClick={() => { if (!blocked) onPick(key); }} className={blocked ? `${quietClass} cursor-not-allowed opacity-40` : picked ? buttonClass : quietClass}>{row.name}</button>
                    </RuleTooltip>
                );
            })}
        </div>
    );
}

function talentRequirementsMet(talent, grantedNames) {
    return (talent.prerequisites_json || []).every((requirement) => requirementMet(requirement, grantedNames));
}

function requirementMet(text, grantedNames) {
    const raw = String(text).trim().replace(/\.$/, '');
    if (!raw || normalizeFeature(raw) === 'none') return true;
    const level = raw.match(/character level (\d+)/i);
    if (level) return Number(level[1]) <= 1;
    if (normalizeFeature(raw) === 'arms mastery for that weapon') return grantedNames.has('arms mastery');
    if (/\bor\b/i.test(raw) && !/\band\b/i.test(raw)) {
        return raw.split(/\s+or\s+/i).some((part) => requirementMet(part, grantedNames));
    }
    if (/\band\b/i.test(raw)) {
        return raw.split(/\s+and\s+/i).every((part) => requirementMet(part, grantedNames));
    }
    if (/wielded shield/i.test(raw)) return grantedNames.has('shield');
    return grantedNames.has(normalizeFeature(raw));
}

function skillChoiceDetail(skill, ranks) {
    const attribute = skill.attribute?.name;
    const kind = skill.is_attack_skill ? 'an attack skill' : 'a nonattack skill';
    const association = attribute ? `${skill.name} is ${kind} associated with ${attribute}.` : `${skill.name} is ${kind}.`;
    const amount = ranks === 1 ? '+1 rank' : `+${ranks} ranks`;
    return `Gain ${amount} in ${skill.name}. ${association}`;
}

function damageTypeDetail(name, kind) {
    if (kind === 'weakness') {
        return `Uncanny Weakness to ${name}. On a failed Endurance check against that damage, Weakness adds 1 wound after protective saves.`;
    }
    return `Uncanny Resistance to ${name}. On a successful Endurance check against that damage, Resistance prevents up to 1 remaining wound and prevents Suffering.`;
}

function spellChoiceDetail(spell) {
    const facts = [
        spell.order?.name,
        spell.tier != null ? `Level ${spell.tier}` : null,
        spell.casting_time ? `Casting ${spell.casting_time}` : null,
        spell.targeting_json?.text,
        spell.duration_json?.text,
    ].filter(Boolean).join(' · ');
    const empowered = spell.empowered_effects_json?.text;
    return [spell.flavor_text, facts, spell.rules_text, empowered ? `Empowered. ${empowered}` : null].filter(Boolean).join('\n\n');
}

const ATTRIBUTES = [
    { key: 'agility', name: 'Agility', detail: 'Agility measures coordination and speed.' },
    { key: 'intellect', name: 'Intellect', detail: 'Intellect covers learning and perception.' },
    { key: 'resolve', name: 'Resolve', detail: 'Resolve measures inner conviction.' },
    { key: 'social', name: 'Social', detail: 'Social reflects presence and influence.' },
    { key: 'strength', name: 'Strength', detail: 'Strength measures physical force.' },
    { key: 'vitality', name: 'Vitality', detail: 'Vitality reflects health and endurance.' },
];

function attributeBoostDetail(name) {
    const clean = name.replace(/\s*\+\d+.*/, '').trim();
    const match = ATTRIBUTES.find((attribute) => attribute.name.toLowerCase() === clean.toLowerCase());
    return `Increase ${match?.name || clean} by 1 step. ${match?.detail || ''}`.trim();
}

function primarySkillDetail(skill) {
    const attribute = skill.attribute?.name;
    const kind = skill.is_attack_skill ? 'an attack skill' : 'a nonattack skill';
    const association = attribute ? `${skill.name} is ${kind} associated with ${attribute}.` : `${skill.name} is ${kind}.`;
    return `Every listed primary skill increases by 2 ranks. ${association}`;
}

function skillTrainingDetail(name, skills) {
    const skill = skills.find((row) => row.name.toLowerCase() === name.trim().toLowerCase());
    if (!skill) return `Gain +1 rank in ${name}.`;
    const attribute = skill.attribute?.name;
    const kind = skill.is_attack_skill ? 'an attack skill' : 'a nonattack skill';
    const association = attribute ? `${skill.name} is ${kind} associated with ${attribute}.` : `${skill.name} is ${kind}.`;
    return `Gain +1 rank in ${skill.name}. ${association}`;
}

const DAMAGE_TYPES = [
    { key: 'Fire', name: 'Fire' },
    { key: 'Storm', name: 'Storm' },
    { key: 'Cold', name: 'Cold' },
];

function AttributeFocusChoice({ value, onChange }) {
    return (
        <div className="flex flex-wrap gap-2">
            {ATTRIBUTES.map((attribute) => (
                <RuleTooltip key={attribute.key} above text={`${attribute.detail} Attribute Focus increases it by 1 step.`}>
                    <button type="button" onClick={() => onChange(attribute.key)} className={value === attribute.key ? buttonClass : quietClass}>{attribute.name}</button>
                </RuleTooltip>
            ))}
        </div>
    );
}

function ruleDetail(feature) {
    if (!feature) return '';
    return [feature.flavor_text, feature.rules_text].filter(Boolean).join('\n\n').replace(/\\(?=\S)/g, '').replaceAll('**', '');
}

function findFeature(catalog, name, category) {
    const key = normalizeFeature(name);
    return (category && catalog.get(`${category}:${key}`)) || catalog.get(key);
}

function RuleTooltip({ text, children, block = false, wide = false, above = false }) {
    if (!text) return children;
    const place = above ? 'bottom-full mb-2' : 'top-full mt-2';
    function keepInside(event) {
        if (!above) return;
        const anchor = event.currentTarget;
        const tip = anchor.querySelector('[role="tooltip"]');
        const dialog = anchor.closest('[role="dialog"]');
        if (!tip || !dialog) return;
        const button = anchor.getBoundingClientRect();
        const box = dialog.getBoundingClientRect();
        const pad = 24;
        const width = wide ? 384 : 320;
        let left = button.left;
        if (left + width > box.right - pad) left = box.right - pad - width;
        if (left < box.left + pad) left = box.left + pad;
        const hidden = getComputedStyle(tip).display === 'none';
        if (hidden) tip.style.display = 'block';
        const tipBox = tip.getBoundingClientRect();
        if (hidden) tip.style.display = '';
        const openBelow = button.top - (box.top + pad) < tipBox.height + 8;
        tip.classList.toggle('bottom-full', !openBelow);
        tip.classList.toggle('mb-2', !openBelow);
        tip.classList.toggle('top-full', openBelow);
        tip.classList.toggle('mt-2', openBelow);
        tip.style.transform = `translateX(${left - button.left}px)`;
    }
    return (
        <span className={`group relative ${block ? 'block' : 'inline-block'}`} onMouseEnter={keepInside} onFocus={keepInside}>
            {children}
            <span role="tooltip" className={`absolute left-0 z-[80] hidden border border-line bg-ink p-3 text-left text-xs leading-5 whitespace-pre-wrap text-parchment normal-case shadow-xl group-hover:block ${place} ${wide ? 'max-h-72 w-96 overflow-y-auto' : 'pointer-events-none w-80'}`}>
                {text}
            </span>
        </span>
    );
}

const LEVEL_GAIN_RULES = {
    'attack skill increase': 'Gain +1 rank in one of Melee, Ranged, Unarmed, Sorcery, Faith, or Shamanism.',
    '+1 attack': 'You may make one additional weapon or unarmed attack on your turn, paying the usual minor action and multiple-attack penalty.',
    'rogue +1': 'Rogue Advancement: +1 Initiative Bonus and +2 percentage points to your critical range.',
    'martial +1': 'Martial Advancement: +1 maximum Wound, and choose one eligible talent or tactic.',
    'magus +1': 'Spellcaster Advancement: +1 Sorcerous Potential, to a maximum of 5. Learn two spells and gain two Soul slots.',
};

function roleTooltip(role) {
    if (!role) return '';
    const label = role.name === 'Magus' ? 'Spellcaster' : role.name;
    const increase = {
        Magus: LEVEL_GAIN_RULES['magus +1'],
        Martial: LEVEL_GAIN_RULES['martial +1'],
        Rogue: LEVEL_GAIN_RULES['rogue +1'],
    }[role.name];
    return [role.description ? `${label} starting role: ${role.description}` : '', increase].filter(Boolean).join('\n\n');
}

function displayFeatureName(name) {
    return {
        'magus +1': 'Spellcaster Advancement',
        'martial +1': 'Martial Advancement',
        'rogue +1': 'Rogue Advancement',
    }[normalizeFeature(name)] || name;
}

function levelGainDetail(name, feature) {
    const rules = ruleDetail(feature);
    if (rules) return rules;
    const key = normalizeFeature(name);
    if (LEVEL_GAIN_RULES[key]) return LEVEL_GAIN_RULES[key];
    const enhanced = name.match(/^(.+) Enhanced$/);
    if (enhanced) return `${enhanced[1]} checks are permanently Enhanced.`;
    if (/weapon technique/i.test(name)) return 'Learn one Weapon Technique for a weapon type that lists it.';
    if (/spell technique/i.test(name)) return 'Gain one Spell Technique.';
    return name;
}

function FeatureLabel({ name, catalog, category, prefix = '' }) {
    if (!name) return null;
    const feature = findFeature(catalog, name, category);
    return (
        <RuleTooltip text={levelGainDetail(name, feature)}>
            <span className="underline decoration-line decoration-dotted underline-offset-2">{prefix}{displayFeatureName(name)}</span>
        </RuleTooltip>
    );
}

function SkillPick({ title, skills, selected, blocked = [], limit, onToggle }) {
    return (
        <section>
            <h2 className="font-display text-2xl">{title}</h2>
            <div className="mt-2 flex flex-wrap gap-2">
                {skills.map((skill) => {
                    const unavailable = blocked.includes(skill.key);
                    return (
                        <button type="button" key={skill.id || skill.key} disabled={unavailable} onClick={() => onToggle(skill.key)} className={unavailable ? `${quietClass} cursor-not-allowed opacity-40` : selected.includes(skill.key) ? buttonClass : quietClass}>{skill.name}</button>
                    );
                })}
            </div>
            <p className="mt-1 text-xs text-silver">{selected.length}/{limit}</p>
        </section>
    );
}

function TechniquePick({ techniques, selected, onPick }) {
    return (
        <div className="flex flex-wrap gap-2">
            {techniques.map((row) => (
                <RuleTooltip key={row.id} text={row.detail} wide above>
                    <button type="button" onClick={() => onPick(row.id)} className={Number(selected) === Number(row.id) ? buttonClass : quietClass}>{row.weapon} · {row.technique}</button>
                </RuleTooltip>
            ))}
        </div>
    );
}

function SpellGrantPick({ grant, spells, potential, value, onChange }) {
    const picked = value.spells || [];
    const order = value.order || (grant.orders?.length === 1 ? grant.orders[0] : '');
    const cap = grant.maxTier == null ? potential : Math.min(potential, grant.maxTier);
    const list = spells.filter((spell) => Number(spell.tier) <= cap);
    function allowed(spell) {
        if (grant.allOrders) return true;
        if (grant.chooseOrder) return Boolean(order) && spell.order?.name === order;
        return grant.orders?.includes(spell.order?.name);
    }
    function toggle(spell) {
        if (!allowed(spell)) return;
        const next = picked.includes(spell.id) ? picked.filter((item) => item !== spell.id) : picked.length >= grant.count ? picked : [...picked, spell.id];
        onChange({ ...value, order, spells: next });
    }
    return (
        <div>
            {grant.chooseOrder && (
                <div className="mb-3 flex flex-wrap gap-2">
                    {SPELL_ORDERS.map((name) => (
                        <button key={name} type="button" className={order === name ? buttonClass : quietClass} onClick={() => onChange({ order: name, spells: [] })}>{name}</button>
                    ))}
                </div>
            )}
            <div className="flex flex-wrap gap-2">
                {list.map((spell) => {
                    const open = allowed(spell);
                    return (
                        <RuleTooltip key={spell.id} text={spellChoiceDetail(spell)} wide above>
                            <button type="button" aria-disabled={!open} onClick={() => toggle(spell)} className={!open ? `${quietClass} cursor-not-allowed opacity-40` : picked.includes(spell.id) ? buttonClass : quietClass}>{spell.name}</button>
                        </RuleTooltip>
                    );
                })}
            </div>
            <p className="mt-1 text-xs text-silver">{picked.length}/{grant.count}. Level {cap} or lower.</p>
        </div>
    );
}

function SpellPick({ spells, value, onChange, order = '' }) {
    function toggle(spell) {
        const open = order && spell.order?.name === order;
        if (!open) return;
        if (value.includes(spell.id)) onChange(value.filter((item) => item !== spell.id));
        else if (value.length < 2) onChange([...value, spell.id]);
    }
    return (
        <div className="flex flex-wrap gap-2">
            {spells.map((spell) => {
                const open = order && spell.order?.name === order;
                return (
                    <RuleTooltip key={spell.id} text={spellChoiceDetail(spell)} wide above>
                        <button type="button" aria-disabled={!open} onClick={() => toggle(spell)} className={!open ? `${quietClass} cursor-not-allowed opacity-40` : value.includes(spell.id) ? buttonClass : quietClass}>{spell.name}</button>
                    </RuleTooltip>
                );
            })}
        </div>
    );
}
