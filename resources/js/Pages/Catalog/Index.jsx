import { useEffect, useMemo, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import Shell, { Mark, Portrait, buttonClass, inputClass, quietClass } from '../../Components/Shell';

const featureCategories = [
    ['creation_primary', 'Primary'],
    ['creation_secondary', 'Secondary'],
    ['background', 'Background'],
    ['class', 'Class'],
    ['tactic', 'Tactic'],
];

const entryName = {
    species: 'species',
    backgrounds: 'background',
    classes: 'class',
    specializations: 'specialization',
    items: 'item',
    talents: 'talent or tactic',
    features: 'feature',
    spells: 'spell',
};

const fieldLabels = {
    description: 'Description',
    primary: 'Primary features',
    secondary: 'Secondary features',
    attributes: 'Attributes',
    skills: 'Skills',
    feature: 'Feature',
    equipment: 'Equipment',
    role: 'Role',
    primary_skills: 'Primary skills',
    progression: 'Level based features',
    category: 'Category',
    cost_silver: 'Cost in silver',
    weight: 'Weight',
    flavor_text: 'Flavor',
    rules_text: 'Rules',
    activation_type: 'Activation',
    order: 'Order',
    tier: 'Level',
    casting_time: 'Casting time',
    range_text: 'Range',
    duration_text: 'Duration',
};

const requiredFor = {
    species: ['name', 'description'],
    backgrounds: ['name', 'description'],
    classes: ['name', 'description', 'role', 'primary_skills', 'progression'],
    specializations: ['name', 'description', 'progression'],
    items: ['name', 'description', 'category', 'cost_silver', 'weight'],
    talents: ['name', 'rules_text', 'activation_type'],
    features: ['name', 'category', 'rules_text', 'activation_type'],
    spells: ['name', 'order', 'tier', 'rules_text', 'casting_time', 'range_text', 'duration_text'],
};

const itemCategories = ['weapon', 'armor', 'ammo', 'gear', 'alchemical'];
const activationTypes = ['passive', 'minor'];

function filled(value) {
    if (value === 0 || value === false) return true;
    return String(value ?? '').trim() !== '';
}

const fieldsFor = {
    species: ['description', 'primary', 'secondary'],
    backgrounds: ['description', 'attributes', 'skills', 'feature', 'equipment'],
    classes: ['description', 'role', 'primary_skills', 'progression'],
    specializations: ['description', 'progression'],
    items: ['description', 'category', 'cost_silver', 'weight'],
    talents: ['flavor_text', 'rules_text', 'activation_type'],
    features: ['category', 'flavor_text', 'rules_text', 'activation_type'],
    spells: ['order', 'tier', 'flavor_text', 'rules_text', 'casting_time', 'range_text', 'duration_text'],
};

function Tip({ text, children }) {
    if (!text) return children;
    function keepInside(event) {
        const anchor = event.currentTarget;
        const tip = anchor.querySelector('[role="tooltip"]');
        if (!tip) return;
        const box = anchor.getBoundingClientRect();
        const width = Math.min(320, window.innerWidth - 32);
        tip.style.width = `${width}px`;
        let shift = 0;
        if (box.left + width > window.innerWidth - 16) {
            shift = window.innerWidth - 16 - width - box.left;
        }
        if (box.left + shift < 16) {
            shift = 16 - box.left;
        }
        tip.style.transform = `translateX(${shift}px)`;
    }
    return (
        <span className="group relative inline-block" onMouseEnter={keepInside} onFocus={keepInside}>
            {children}
            <span role="tooltip" className="pointer-events-none absolute top-full left-0 z-30 mt-2 hidden w-80 border border-line bg-ink p-3 text-left text-xs leading-5 whitespace-pre-wrap text-parchment normal-case shadow-xl group-hover:block">
                {text}
            </span>
        </span>
    );
}

export default function Index({ type, title, rows, form: options = {} }) {
    const art = usePage().props.art || {};
    const errors = usePage().props.errors || {};
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState({ name: '' });
    const [query, setQuery] = useState('');
    const [order, setOrder] = useState('');
    const [level, setLevel] = useState('');
    const fields = fieldsFor[type] || ['description'];
    const required = requiredFor[type] || ['name'];
    const [missing, setMissing] = useState([]);
    const orders = useMemo(() => [...new Set(rows.map((row) => row.edit?.order).filter(Boolean))].sort((a, b) => a.localeCompare(b)), [rows]);
    const levels = useMemo(() => [...new Set(rows.map((row) => row.edit?.tier).filter((tier) => tier !== null && tier !== undefined && tier !== ''))].sort((a, b) => Number(a) - Number(b)), [rows]);
    const visible = useMemo(() => {
        if (type !== 'spells' && type !== 'items') return rows;
        const terms = query.trim().toLowerCase().split(/\s+/).filter(Boolean);
        return rows.filter((row) => {
            if (type === 'spells') {
                if (order && row.edit?.order !== order) return false;
                if (level !== '' && Number(row.edit?.tier) !== Number(level)) return false;
            }
            if (!terms.length) return true;
            const text = [row.name, row.detail, row.flavor, row.description, row.casting_time, row.range, row.duration, row.empowered, row.edit?.category].join(' ').toLowerCase();
            return terms.every((term) => text.includes(term));
        });
    }, [rows, query, order, level, type]);

    function start(row) {
        setEditing(row?.id || null);
        setMissing([]);
        setForm({ name: row?.name || '', description: row?.description || '', ...(row?.edit || {}) });
        setOpen(true);
    }

    function save(event) {
        event.preventDefault();
        const absent = required.filter((field) => !filled(form[field]));
        setMissing(absent);
        if (absent.length) return;
        const url = editing ? `/${type}/${editing}` : `/${type}`;
        router.visit(url, { method: editing ? 'patch' : 'post', data: form, preserveScroll: true, onSuccess: () => setOpen(false) });
    }

    useEffect(() => {
        if (!open) return;
        document.getElementById('catalog-form')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, [open, editing]);

    function fieldControl(field) {
        const needed = required.includes(field);
        const invalid = missing.includes(field);
        const controlClass = `${inputClass} mt-1 normal-case ${invalid ? 'border-maroon' : ''}`;
        if (field === 'category' && type === 'features') {
            return (
                <select required={needed} className={controlClass} value={form.category || ''} onChange={(e) => setForm({ ...form, category: e.target.value })}>
                    <option value="">Choose a category</option>
                    {featureCategories.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                </select>
            );
        }
        if (field === 'category' && type === 'items') {
            return (
                <select required={needed} className={controlClass} value={form.category || ''} onChange={(e) => setForm({ ...form, category: e.target.value })}>
                    <option value="">Choose a category</option>
                    {itemCategories.map((value) => <option key={value} value={value}>{value}</option>)}
                </select>
            );
        }
        if (field === 'role') {
            return (
                <select required={needed} className={controlClass} value={form.role || ''} onChange={(e) => setForm({ ...form, role: e.target.value })}>
                    <option value="">Choose a role</option>
                    {(options.roles || []).map((name) => <option key={name} value={name}>{name === 'Magus' ? 'Spellcaster' : name}</option>)}
                </select>
            );
        }
        if (field === 'order') {
            const choices = options.orders?.length ? options.orders : orders;
            return (
                <select required={needed} className={controlClass} value={form.order || ''} onChange={(e) => setForm({ ...form, order: e.target.value })}>
                    <option value="">Choose an order</option>
                    {choices.map((name) => <option key={name} value={name}>{name}</option>)}
                </select>
            );
        }
        if (field === 'activation_type') {
            return (
                <select required={needed} className={controlClass} value={form.activation_type || ''} onChange={(e) => setForm({ ...form, activation_type: e.target.value })}>
                    <option value="">Choose an activation</option>
                    {activationTypes.map((value) => <option key={value} value={value}>{value}</option>)}
                </select>
            );
        }
        if (['tier', 'cost_silver', 'weight'].includes(field)) {
            return <input required={needed} type="number" min="0" step={field === 'weight' ? '0.1' : '1'} className={controlClass} value={form[field] ?? ''} onChange={(e) => setForm({ ...form, [field]: e.target.value })} />;
        }
        if (field.includes('text') || ['description', 'progression', 'rules_text', 'flavor_text'].includes(field)) {
            const rows = ['description', 'rules_text', 'progression'].includes(field) ? 4 : 2;
            return <textarea required={needed} className={controlClass} rows={rows} value={form[field] || ''} onChange={(e) => setForm({ ...form, [field]: e.target.value })} />;
        }
        return <input required={needed} className={controlClass} value={form[field] || ''} onChange={(e) => setForm({ ...form, [field]: e.target.value })} />;
    }

    return (
        <Shell title={title}>
            <div className="mb-4 flex flex-wrap items-center gap-3">
                {type === 'items' && (
                    <input className={`${inputClass} min-w-[16rem] flex-1`} placeholder="Search items by name, category, or description" value={query} onChange={(event) => setQuery(event.target.value)} />
                )}
                {type === 'spells' && (
                    <>
                        <input className={`${inputClass} min-w-[16rem] flex-1`} placeholder="Search spells by name, order, or effect" value={query} onChange={(event) => setQuery(event.target.value)} />
                        <select className="shrink-0 border border-line bg-ink px-3 py-2 text-parchment outline-none focus:border-silver" value={order} onChange={(event) => setOrder(event.target.value)} aria-label="Order">
                            <option value="">All orders</option>
                            {orders.map((name) => <option key={name} value={name}>{name}</option>)}
                        </select>
                        <select className="shrink-0 border border-line bg-ink px-3 py-2 text-parchment outline-none focus:border-silver" value={level} onChange={(event) => setLevel(event.target.value)} aria-label="Level">
                            <option value="">All levels</option>
                            {levels.map((tier) => <option key={tier} value={tier}>Level {tier}</option>)}
                        </select>
                    </>
                )}
                <button className={`${buttonClass} ${type === 'spells' || type === 'items' ? '' : 'ml-auto'}`} onClick={() => start(null)}>Design new</button>
            </div>
            {open && (
                <form id="catalog-form" onSubmit={save} className="mb-6 scroll-mt-24 space-y-3 border border-silver bg-panel p-4">
                    <h2 className="font-display text-2xl">{editing ? 'Edit' : 'New'} {entryName[type] || 'entry'}</h2>
                    <p className="text-xs text-silver">Fields marked * are required.</p>
                    <label className="block text-xs tracking-widest text-silver uppercase">Name <span className="text-maroon">*</span>
                        <input required className={`${inputClass} mt-1 normal-case ${missing.includes('name') ? 'border-maroon' : ''}`} value={form.name || ''} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                    </label>
                    {fields.map((field) => (
                        <label key={field} className="block text-xs tracking-widest text-silver uppercase">{fieldLabels[field] || field}{required.includes(field) && <span className="text-maroon"> *</span>}
                            {fieldControl(field)}
                        </label>
                    ))}
                    {missing.length > 0 && <p className="text-sm text-maroon">Fill every required field before saving.</p>}
                    {Object.entries(errors).map(([field, message]) => <p key={field} className="text-sm text-maroon">{message}</p>)}
                    <button className={buttonClass}>Save</button>
                </form>
            )}
            {type === 'spells' && (query.trim() || order || level !== '') && (
                <p className="mb-4 text-sm text-silver">{visible.length === 0 ? 'No spells match.' : `${visible.length} ${visible.length === 1 ? 'spell' : 'spells'}`}</p>
            )}
            {type === 'items' && query.trim() && (
                <p className="mb-4 text-sm text-silver">{visible.length === 0 ? 'No items match.' : `${visible.length} ${visible.length === 1 ? 'item' : 'items'}`}</p>
            )}
            <div className="grid gap-3 md:grid-cols-2">
                {visible.map((row) => (
                    <article key={row.id} className={`flex items-start gap-4 border border-line bg-panel p-4 ${type === 'talents' ? 'min-h-64' : ''}`}>
                        {type === 'species'
                            ? <Portrait src={row.portrait_url} name={row.name} size="2xl" />
                            : art.sigil
                                ? <img src={art.sigil} alt="" className="h-16 w-16 shrink-0 object-contain" />
                                : <Mark letter={row.name.slice(0, 1)} />}
                        <div className="min-w-0 flex-1">
                            <h2 className="font-display text-2xl">{row.name}</h2>
                            {type === 'classes' && row.detail && (
                                <div className="mt-1">
                                    <Tip text={row.detail_rules}>
                                        <span className="text-xs tracking-wide text-maroon uppercase underline decoration-line decoration-dotted underline-offset-2">{row.detail}</span>
                                    </Tip>
                                </div>
                            )}
                            {type === 'classes' && row.bonuses?.length > 0 && (
                                <div className="mt-3">
                                    <p className="text-xs tracking-widest text-silver uppercase">Bonuses to all primary skills</p>
                                    <div className="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-silver">
                                        {row.bonuses.map((bonus) => (
                                            <Tip key={bonus.name} text={bonus.rules}>
                                                <span className="underline decoration-line decoration-dotted underline-offset-2">{bonus.name}</span>
                                            </Tip>
                                        ))}
                                    </div>
                                </div>
                            )}
                            {type === 'classes' && row.features?.length > 0 && (
                                <div className="mt-3">
                                    <p className="text-xs tracking-widest text-silver uppercase">Level Based Features</p>
                                    <div className="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-silver">
                                        {row.features.map((feature) => (
                                            <Tip key={`${feature.level}-${feature.name}`} text={feature.rules || feature.name}>
                                                <span className="underline decoration-line decoration-dotted underline-offset-2">{feature.level}: {feature.name}</span>
                                            </Tip>
                                        ))}
                                    </div>
                                </div>
                            )}
                            {type === 'backgrounds' && row.bonuses?.length > 0 && (
                                <div className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-silver">
                                    {row.bonuses.map((bonus) => (
                                        <Tip key={bonus.name} text={bonus.rules}>
                                            <span className="underline decoration-line decoration-dotted underline-offset-2">{bonus.name}</span>
                                        </Tip>
                                    ))}
                                </div>
                            )}
                            {type === 'backgrounds' && row.features?.length > 0 && (
                                <div className="mt-3">
                                    <p className="text-xs tracking-widest text-silver uppercase">Background Feature</p>
                                    <div className="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs tracking-wide text-maroon uppercase">
                                        {row.features.map((feature) => (
                                            <Tip key={feature.name} text={feature.rules}>
                                                <span className="underline decoration-line decoration-dotted underline-offset-2">{feature.name}</span>
                                            </Tip>
                                        ))}
                                    </div>
                                </div>
                            )}
                            {type === 'species' && row.features?.length > 0 ? (
                                <div className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs tracking-wide text-maroon uppercase">
                                    {row.features.map((feature) => (
                                        <Tip key={feature.name} text={feature.rules}>
                                            <span className="underline decoration-line decoration-dotted underline-offset-2">{feature.name}</span>
                                        </Tip>
                                    ))}
                                </div>
                            ) : type === 'specializations' && row.features?.length > 0 ? (
                                <div className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-silver">
                                    {row.features.map((feature) => (
                                        <Tip key={`${feature.level}-${feature.name}`} text={feature.rules || feature.name}>
                                            <span className="underline decoration-line decoration-dotted underline-offset-2">{feature.level}: {feature.name}</span>
                                        </Tip>
                                    ))}
                                </div>
                            ) : row.detail && !['classes', 'backgrounds'].includes(type) && <p className="text-xs tracking-wide text-maroon uppercase">{row.detail}</p>}
                            {type === 'spells' && row.flavor && <p className="mt-2 text-sm italic text-silver">{row.flavor}</p>}
                            {type === 'spells' && (
                                <div className="mt-2 space-y-1 text-sm text-parchment">
                                    {row.casting_time && <p>Casting: {row.casting_time}</p>}
                                    {row.range && <p>Range: {row.range}</p>}
                                    {row.duration && <p>Duration: {row.duration}</p>}
                                </div>
                            )}
                            {type === 'talents' && row.requirements && <p className="mt-2 text-sm text-parchment">Requirements: {row.requirements}</p>}
                            {type === 'talents' && row.cost && <p className="mt-1 text-sm text-parchment">Cost: {row.cost}</p>}
                            {type === 'talents' && row.timing && <p className="mt-1 text-sm text-parchment">Timing: {row.timing}</p>}
                            {type === 'talents' && row.flavor && <p className="mt-2 text-sm italic text-silver">{row.flavor}</p>}
                            <p className={`mt-2 text-sm text-parchment-dim ${type === 'talents' || type === 'spells' ? 'line-clamp-6' : 'line-clamp-4'}`}>{row.description}</p>
                            {type === 'spells' && row.empowered && <p className="mt-2 text-sm leading-6 text-parchment-dim"><span className="text-parchment">Empowered.</span> {row.empowered}</p>}
                            {row.editable && (
                                <div className="mt-3 flex gap-2">
                                    <button className={quietClass} onClick={() => start(row)}>Edit</button>
                                    <button className={quietClass} onClick={() => router.delete(`/${type}/${row.id}`)}>Delete</button>
                                </div>
                            )}
                        </div>
                    </article>
                ))}
            </div>
        </Shell>
    );
}
