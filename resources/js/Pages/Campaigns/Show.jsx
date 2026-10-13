import { useEffect, useRef, useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import Shell, { CircumstanceSelect, Portrait, adjustedAttackTier, buttonClass, chanceLabel, inputClass, quietClass } from '../../Components/Shell';
import HexMap from '../../Components/HexMap';
import ConditionPicker, { addCharacterCondition, addCreatureCondition, removeCharacterCondition, removeCreatureCondition } from '../../Components/ConditionPicker';
import LogText from '../../Components/LogText';

function logLine(event) {
    const payload = event.payload || {};
    if (event.type === 'turn') {
        if (payload.summary) return payload.summary;
        if (payload.combatant) return `It is ${payload.combatant}'s turn.${payload.round ? ` Round ${payload.round}.` : ''}`;
    }
    if ((event.type === 'attack' || event.type === 'defeat' || event.type === 'check' || event.type === 'dice' || event.type === 'initiative') && payload.summary) {
        return event.type === 'attack'
            ? payload.summary.replace(/ using a Major Action and a Minor Action| using a Minor Action and a Major Action| using a Major Action| using a Minor Action/g, '')
            : payload.summary;
    }
    if (event.type === 'attack') {
        const attacker = payload.attacker || payload.npc || payload.skill || 'Someone';
        const target = payload.target;
        const weapon = payload.attack || payload.weapon || 'an attack';
        let line = target ? `${attacker} attacked ${target} with ${weapon}.` : `${attacker} attacked with ${weapon}.`;
        if (payload.percent != null) line += ` (${payload.percent}% chance to hit.)`;
        const roll = payload.roll ?? (payload.display && payload.display !== '—' ? payload.display : null);
        if (roll != null) line += ` Rolled ${roll}.`;
        if (payload.save) return `${line} The target makes the save.`;
        if (payload.success === true) {
            line += payload.critical ? ' Critical hit!' : ' Hit!';
            if (target && payload.damage != null) line += ` ${target} took ${payload.damage} damage.`;
        } else if (payload.success === false) {
            line += ' Miss!';
        }
        return line;
    }
    return `${event.type} ${payload.note || payload.skill || ''} ${payload.display || ''} ${payload.success === true ? 'hit' : ''} ${payload.success === false ? 'miss' : ''} ${payload.critical ? 'crit' : ''} ${payload.damage ? `${payload.damage} dmg` : ''}`.replace(/\s+/g, ' ').trim();
}

function clampScale(value) {
    const number = Number(value);
    if (!Number.isFinite(number)) return 1;
    return Math.min(2, Math.max(0.5, Math.round(number * 20) / 20));
}

function labelOf(value) {
    return value.split(/([\s-])/).map((part) => (part === ' ' || part === '-' ? part : part.charAt(0).toUpperCase() + part.slice(1))).join('');
}

function Tip({ text, children }) {
    if (!text) return children;
    return (
        <span className="group relative inline-block">
            {children}
            <span role="tooltip" className="pointer-events-none absolute top-full left-0 z-30 mt-2 hidden w-64 border border-line bg-ink p-3 text-left text-xs leading-5 text-parchment normal-case shadow-xl group-hover:block">
                {text}
            </span>
        </span>
    );
}

function Menu({ label, open, onToggle, tip, active = false, children }) {
    return (
        <span className="relative inline-block">
            <Tip text={open ? null : tip}>
                <button type="button" className={open || active ? buttonClass : quietClass} onClick={onToggle}>{label}</button>
            </Tip>
            {open && (
                <span className="absolute top-full left-0 z-40 mt-1 flex min-w-[9.5rem] flex-col gap-1 border border-line bg-ink p-1 shadow-xl">
                    {children}
                </span>
            )}
        </span>
    );
}

const mapLabels = {
    both: 'Block',
};

const mapTools = {
    move: 'Drag tokens. This leaves painting mode, so clicks do not mark hexes.',
    los: 'Click or drag across hexes to block line of sight. The outline turns maroon.',
    both: 'Click or drag across hexes to block movement and sight with rock, tree, or solid black.',
    erase: 'Click or drag to lift fog, lights, movement blocks, and sight blocks.',
    'erase-all': 'Remove every light, movement block, sight block, and fog of war on this map. Tokens stay.',
    light: 'Click a hex to place a light source.',
    background: 'Choose a picture to show behind the hexes.',
    grid: 'Choose hexes, squares, or hide the grid.',
    day: 'The whole map is lit.',
    twilight: 'The map is dim, but still visible.',
    'night-gloom': 'The map is dark except around lights. Unlit NPCs stay hidden.',
    fog: 'Drag across hexes to cover them with black fog. It lifts as characters approach, and Erase removes it.',
    'gm-vision': 'Show this map without fog or lighting darkness. Only your screen changes.',
    scale: 'Change how large the hexes, squares, and tokens are. Players see the same size.',
    measure: 'Click and drag across the grid. Each hex or square is 5 feet. Choose Measure again to move the map.',
    open: 'Players can drag their own tokens at any time.',
    turn: 'A player can drag only the token whose turn it is.',
    locked: 'Players cannot drag tokens. The GM still can.',
};

export default function Show({ campaign, scene, encounter, roster, conditions = [] }) {
    const [tool, setTool] = useState('move');
    const [blockIcon, setBlockIcon] = useState('rock');
    const [menu, setMenu] = useState(null);
    const [gmVision, setGmVision] = useState(false);
    const storedScale = clampScale(scene?.settings_json?.scale ?? 1);
    const [scale, setScale] = useState(storedScale);
    const pendingScale = useRef(null);
    const scaleTimer = useRef(null);
    const background = scene?.settings_json?.background || 'none';
    const grid = scene?.settings_json?.grid === 'square' ? 'square' : 'hex';
    const showGrid = scene?.settings_json?.show_grid !== false;
    const [tab, setTab] = useState('map');
    const fight = useForm({ name: 'Skirmish' });
    const userId = usePage().props.auth.user.id;
    const art = usePage().props.art || {};
    const mapBackgrounds = usePage().props.mapBackgrounds || ['field', 'dungeon'];
    const movement = scene?.settings_json?.movement || 'open';

    useEffect(() => {
        if (!window.Echo) return undefined;
        const channel = window.Echo.private(`campaign.${campaign.id}`);
        channel.listen('.table.updated', () => router.reload({ preserveScroll: true }));
        return () => window.Echo.leave(`campaign.${campaign.id}`);
    }, [campaign.id]);

    useEffect(() => {
        if (pendingScale.current == null) setScale(storedScale);
    }, [storedScale]);

    useEffect(() => () => window.clearTimeout(scaleTimer.current), []);

    function changeScale(value) {
        const next = clampScale(value);
        setScale(next);
        if (!campaign.is_gm || !scene) return;
        pendingScale.current = next;
        window.clearTimeout(scaleTimer.current);
        scaleTimer.current = window.setTimeout(() => {
            router.patch(`/scenes/${scene.id}`, { scale: next }, {
                preserveScroll: true,
                onFinish: () => {
                    if (pendingScale.current === next) pendingScale.current = null;
                },
            });
        }, 300);
    }

    const active = encounter?.combatants?.find((row) => row.id === encounter.active_combatant_id);

    return (
        <Shell title={campaign.name}>
            <p className="mb-4 max-w-3xl text-parchment-dim">{campaign.description}</p>
            <div className="mb-4 flex gap-2">
                <button className={tab === 'map' ? buttonClass : quietClass} onClick={() => setTab('map')}>Map</button>
                <button className={tab === 'company' ? buttonClass : quietClass} onClick={() => setTab('company')}>Campaign Characters</button>
            </div>
            {tab === 'company' ? (
                <Company campaign={campaign} roster={roster} encounter={encounter} active={active} fight={fight} conditions={conditions} />
            ) : null}
            {tab === 'map' && <div className="grid gap-6 xl:grid-cols-[1fr_340px]">
                <section>
                    <div className="mb-3 flex flex-wrap gap-2">
                        {scene && (
                            <Tip text={mapTools.measure}>
                                <button type="button" className={tool === 'measure' ? buttonClass : quietClass} onClick={() => { setTool(tool === 'measure' ? 'move' : 'measure'); setMenu(null); }}>Measure</button>
                            </Tip>
                        )}
                        {campaign.is_gm && ['move', 'los', 'light'].map((mode) => (
                            <Tip key={mode} text={mapTools[mode]}>
                                <button className={tool === mode ? buttonClass : quietClass} onClick={() => { setTool(mode); setMenu(null); }}>{mapLabels[mode] || labelOf(mode)}</button>
                            </Tip>
                        ))}
                        {campaign.is_gm && (
                            <Menu label="Block" active={tool === 'both'} open={menu === 'block'} onToggle={() => { setTool('both'); setMenu(menu === 'block' ? null : 'block'); }} tip={mapTools.both}>
                                {['rock', 'tree', 'black'].map((kind) => (
                                    <button key={kind} className={`${tool === 'both' && blockIcon === kind ? buttonClass : quietClass} flex w-full items-center gap-2`} onClick={() => { setTool('both'); setBlockIcon(kind); setMenu(null); }}>
                                        {kind === 'black'
                                            ? <span className="h-6 w-6 border border-line bg-[#070708]" />
                                            : <img src={art[kind]} alt="" className="h-6 w-6 object-contain" />}
                                        {kind === 'black' ? 'Black' : labelOf(kind)}
                                    </button>
                                ))}
                            </Menu>
                        )}
                        {campaign.is_gm && scene && (
                            <Menu label={showGrid ? (grid === 'square' ? 'Square' : 'Hex') : 'Grid off'} open={menu === 'grid'} onToggle={() => setMenu(menu === 'grid' ? null : 'grid')} tip={mapTools.grid}>
                                <button className={`${showGrid && grid === 'hex' ? buttonClass : quietClass} w-full text-left`} onClick={() => { router.patch(`/scenes/${scene.id}`, { grid: 'hex', show_grid: true }); setMenu(null); }}>Hex</button>
                                <button className={`${showGrid && grid === 'square' ? buttonClass : quietClass} w-full text-left`} onClick={() => { router.patch(`/scenes/${scene.id}`, { grid: 'square', show_grid: true }); setMenu(null); }}>Square</button>
                                <button className={`${!showGrid ? buttonClass : quietClass} w-full text-left`} onClick={() => { router.patch(`/scenes/${scene.id}`, { show_grid: false }); setMenu(null); }}>Hide</button>
                            </Menu>
                        )}
                        {campaign.is_gm && scene && (
                            <Menu label="Background" open={menu === 'background'} onToggle={() => setMenu(menu === 'background' ? null : 'background')} tip={mapTools.background}>
                                {[...mapBackgrounds, 'none'].map((name) => (
                                    <button key={name} className={`${background === name ? buttonClass : quietClass} flex w-full items-center gap-2`} onClick={() => { router.patch(`/scenes/${scene.id}`, { background: name }); setMenu(null); }}>
                                        {name !== 'none' && <img src={art[name]} alt="" className="h-6 w-10 object-cover" />}
                                        {labelOf(name)}
                                    </button>
                                ))}
                            </Menu>
                        )}
                        {campaign.is_gm && scene && (
                            <Menu label={labelOf(scene.settings_json?.lighting || 'night-gloom')} open={menu === 'lighting'} onToggle={() => setMenu(menu === 'lighting' ? null : 'lighting')} tip="Choose how dark the map is.">
                                {['day', 'twilight', 'night-gloom'].map((lighting) => (
                                    <button key={lighting} className={`${(scene.settings_json?.lighting || 'night-gloom') === lighting ? buttonClass : quietClass} w-full text-left`} onClick={() => { router.patch(`/scenes/${scene.id}`, { lighting }); setMenu(null); }}>{labelOf(lighting)}</button>
                                ))}
                            </Menu>
                        )}
                        {campaign.is_gm && scene && (
                            <Tip text={mapTools.fog}>
                                <button className={tool === 'fog' ? buttonClass : quietClass} onClick={() => { setTool('fog'); setMenu(null); }}>Fog of war</button>
                            </Tip>
                        )}
                        {campaign.is_gm && scene && (
                            <Menu label={`Scale ${Math.round(scale * 100)}%`} open={menu === 'scale'} onToggle={() => setMenu(menu === 'scale' ? null : 'scale')} tip={mapTools.scale}>
                                <label className="flex w-40 flex-col gap-2 px-2 py-2 text-xs text-silver">
                                    <span>{Math.round(scale * 100)}%</span>
                                    <input className="w-full accent-[#8f3140]" type="range" min="0.5" max="2" step="0.05" value={scale} onChange={(event) => changeScale(event.target.value)} />
                                </label>
                            </Menu>
                        )}
                        {campaign.is_gm && (
                            <Tip text={mapTools['gm-vision']}>
                                <button className={gmVision ? buttonClass : quietClass} onClick={() => setGmVision((on) => !on)}>GM-Vision</button>
                            </Tip>
                        )}
                        {campaign.is_gm && (
                            <span className="ml-auto flex gap-2">
                                <Tip text={mapTools.erase}>
                                    <button className={tool === 'erase' ? buttonClass : quietClass} onClick={() => setTool('erase')}>Erase</button>
                                </Tip>
                                {scene && (
                                    <Tip text={mapTools['erase-all']}>
                                        <button className={quietClass} onClick={() => router.post(`/scenes/${scene.id}/clear-painted`)}>Erase All</button>
                                    </Tip>
                                )}
                            </span>
                        )}
                    </div>
                    {scene && (
                        <div className="relative">
                            <HexMap
                                scene={scene}
                                tokens={scene.tokens || []}
                                isGm={campaign.is_gm}
                                tool={tool}
                                blockIcon={blockIcon}
                                userId={userId}
                                movement={movement}
                                activeCombatantId={encounter?.active_combatant_id}
                                gmVision={campaign.is_gm && gmVision}
                                scale={scale}
                            />
                            <span className="group absolute bottom-3 left-3 z-20">
                                <button type="button" aria-label="Map help" className="flex h-8 w-8 items-center justify-center rounded-full border border-silver bg-ink font-display text-base leading-none text-parchment shadow-lg hover:border-parchment">i</button>
                                <span role="tooltip" className="pointer-events-none absolute bottom-full left-0 z-30 mb-2 hidden w-80 border border-line bg-ink p-3 text-left text-xs leading-5 text-parchment normal-case shadow-xl group-hover:block group-focus-within:block">
                                    Use the mouse wheel to zoom, and drag the map to move the view. Drag a token you control onto an open hex. The GM can leave movement free, lock every token, or allow a player to move only on that character’s turn. An icon marks the active token.
                                </span>
                            </span>
                        </div>
                    )}
                    {campaign.is_gm && scene && (
                        <div className="mt-3 flex flex-wrap gap-2">
                            <Menu label={movement === 'turn' ? 'Their turn' : movement === 'locked' ? 'Locked' : 'Free move'} open={menu === 'movement'} onToggle={() => setMenu(menu === 'movement' ? null : 'movement')} tip="Choose who can drag tokens.">
                                {[
                                    ['open', 'Free move'],
                                    ['turn', 'Their turn'],
                                    ['locked', 'Locked'],
                                ].map(([mode, label]) => (
                                    <button key={mode} className={`${movement === mode ? buttonClass : quietClass} w-full text-left`} onClick={() => { router.patch(`/scenes/${scene.id}`, { movement: mode }); setMenu(null); }}>{label}</button>
                                ))}
                            </Menu>
                        </div>
                    )}
                </section>
                <aside>
                    <Combat campaign={campaign} encounter={encounter} active={active} fight={fight} roster={roster} />
                </aside>
            </div>}
        </Shell>
    );
}

function Combat({ campaign, encounter, active, fight, roster, split = false }) {
    const logRef = useRef(null);
    const page = usePage();
    const userId = page.props.auth?.user?.id;
    const art = page.props.art || {};
    const luckError = page.props.errors?.luck;
    const canEndTurn = campaign.is_gm || Number(active?.controller_user_id) === Number(userId);
    const spenders = (roster?.characters || []).filter((row) => row.in_combat && (row.luck ?? 0) > 0 && (campaign.is_gm || row.can_open));
    const spender = spenders.find((row) => Number(row.combatant_id) === Number(active?.id)) || spenders.find((row) => row.can_open && !campaign.is_gm) || spenders[0];
    const luckTarget = [...(encounter?.events || [])].reverse().find((event) => {
        const roll = Number(event.payload?.roll);
        return Number.isInteger(roll) && roll >= 1 && roll <= 99 && event.payload?.percent != null && !event.payload?.reversed;
    });
    const events = encounter?.events || [];
    const lastEvent = events[events.length - 1]?.id;
    useEffect(() => {
        const log = logRef.current;
        if (!log) return;
        log.scrollTop = log.scrollHeight;
    }, [events.length, lastEvent]);

    const logBody = (
        <div ref={logRef} className={split ? 'mt-3 max-h-80 space-y-2 overflow-y-auto' : 'mt-2 max-h-[32rem] space-y-2 overflow-y-auto'}>
            {events.length === 0 && split && <p className="px-4 py-16 text-center text-sm text-silver">No events yet.<br />Roll initiative to begin.</p>}
            {events.map((event) => (
                <p key={event.id} className="text-xs text-silver">
                    <span className="text-parchment-dim">{event.at}</span> <LogText text={logLine(event)} />
                </p>
            ))}
        </div>
    );

    return (
        <div className={split ? 'space-y-4' : undefined}>
            <section className="border border-line bg-panel p-4">
                <h2 className="flex items-center gap-2 font-display text-2xl">{split && <img src={art.combat} alt="" className="h-8 w-8 shrink-0 object-contain" />} Combat</h2>
                {!encounter && campaign.is_gm && (
                    <form onSubmit={(e) => { e.preventDefault(); fight.post(`/campaigns/${campaign.id}/encounters`); }} className="mt-3 flex gap-2">
                        <input className={inputClass} value={fight.data.name} onChange={(e) => fight.setData('name', e.target.value)} />
                        <button className={buttonClass}>Start</button>
                    </form>
                )}
                {encounter && (
                    <div className="mt-3 text-sm">
                        <p>{encounter.name} · round {encounter.round} · {encounter.status}</p>
                        <p className="mt-1 flex flex-wrap items-center gap-2 text-silver">
                            <span>Turn: {active?.name || '—'} {!split && encounter.actions && `(${encounter.actions.major} major / ${encounter.actions.minor} minor / ${encounter.actions.free} free)`}</span>
                            {spender && luckTarget && (
                                <button type="button" title={`Spend 1 Luck to reverse ${luckTarget.payload.roll} to ${Number(String(luckTarget.payload.roll).padStart(2, '0').split('').reverse().join(''))}. ${spender.luck} Luck left.`} className="inline-flex items-center gap-1 border border-line px-1.5 py-0.5 text-[11px] text-parchment hover:border-maroon" onClick={() => router.post(`/characters/${spender.id}/luck`, { event_id: luckTarget.id }, { preserveScroll: true })}>
                                    <img src={art.luck} alt="" className="h-3.5 w-3.5" /> Luck
                                </button>
                            )}
                        </p>
                        {luckError && <p className="mt-1 text-xs text-maroon">{luckError}</p>}
                        <ul className="mt-3 space-y-1">
                            {encounter.combatants.map((row) => {
                                const acting = Number(row.id) === Number(encounter.active_combatant_id);
                                return (
                                    <li key={row.id} className={`flex items-center gap-2 ${acting ? 'border-l-2 border-maroon pl-2 text-parchment' : 'border-l-2 border-transparent pl-2 text-parchment-dim'}`}>
                                        {acting && <img src={art['turn-icon']} alt="" className="h-6 w-5 shrink-0 object-contain" />}
                                        <span>{!split && acting ? 'Turn · ' : ''}{row.name} · init {row.initiative ?? '—'} · wounds {row.wounds ?? '—'}</span>
                                    </li>
                                );
                            })}
                        </ul>
                        {campaign.is_gm && (split ? (
                            <>
                                <div className="mt-4 flex gap-2">
                                    <button className={`${buttonClass} flex-1`} onClick={() => router.post(`/encounters/${encounter.id}/initiative`)}>Roll initiative</button>
                                    <button className={quietClass} onClick={() => router.post(`/encounters/${encounter.id}/end-turn`)}>End turn</button>
                                </div>
                                <button className={`${quietClass} mt-2 w-full`} onClick={() => router.post(`/encounters/${encounter.id}/clear`)}>Clear combat</button>
                            </>
                        ) : (
                            <div className="mt-3 flex flex-wrap gap-2">
                                <button className={quietClass} onClick={() => router.post(`/encounters/${encounter.id}/initiative`)}>Roll initiative</button>
                                <button className={quietClass} onClick={() => router.post(`/encounters/${encounter.id}/end-turn`)}>End turn</button>
                                <button className={quietClass} onClick={() => router.post(`/encounters/${encounter.id}/clear`)}>Clear combat</button>
                            </div>
                        ))}
                        {!campaign.is_gm && canEndTurn && (
                            <button className={`${quietClass} ${split ? 'mt-4 w-full' : 'mt-3'}`} onClick={() => router.post(`/encounters/${encounter.id}/end-turn`)}>End turn</button>
                        )}
                        {!split && (
                            <>
                                <h3 className="mt-4 border-t border-line pt-3 font-display text-xl">Combat Log</h3>
                                {logBody}
                            </>
                        )}
                    </div>
                )}
            </section>
            {split && (
                <section className="border border-line bg-panel p-4">
                    <h2 className="flex items-center gap-2 font-display text-2xl"><ScrollIcon /> Combat Log</h2>
                    {encounter ? logBody : <p className="px-4 py-16 text-center text-sm text-silver">No events yet.<br />Roll initiative to begin.</p>}
                </section>
            )}
        </div>
    );
}

function Company({ campaign, roster, encounter, active, fight, conditions = [] }) {
    const lastRoll = usePage().props.flash?.roll;
    const actionError = usePage().props.errors?.action;
    const [failedAttack, setFailedAttack] = useState(null);
    const art = usePage().props.art || {};
    const targets = [
        ...(roster.characters || []).map((row) => ({ ...row, type: 'character' })),
        ...(roster.creatures || []).map((row) => ({ ...row, type: 'creature' })),
    ];
    const rememberedTarget = (combatant) => {
        const value = combatant?.last_target || '';
        if (!value) return combatant ? '' : (targets[0] ? `${targets[0].type}:${targets[0].id}` : '');
        return targets.some((row) => `${row.type}:${row.id}` === value) ? value : '';
    };
    const [target, setTarget] = useState(() => rememberedTarget(active));
    const activeId = active?.id ?? null;
    useEffect(() => {
        if (activeId == null) return;
        setTarget(rememberedTarget(active));
    }, [activeId]);
    const [circumstance, setCircumstance] = useState({});

    function rollCheck(creature, name) {
        router.post(`/campaigns/${campaign.id}/checks`, {
            creature_instance_id: creature.id,
            name,
            circumstance: circumstance[`n-${creature.id}`] ?? 0,
        }, { preserveScroll: true });
    }

    function roll(creature, attack) {
        const [targetType, targetId] = (target || '').split(':');
        if (!targetType || !targetId) return;
        setFailedAttack(`n-${creature.id}-${attack}`);
        router.post(`/campaigns/${campaign.id}/attacks`, {
            creature_instance_id: creature.id,
            attack,
            target_type: targetType,
            target_id: targetId,
            circumstance: circumstance[`n-${creature.id}`] ?? 0,
        }, { preserveScroll: true });
    }

    function rollDie(subject, die) {
        router.post(`/campaigns/${campaign.id}/dice`, { ...subject, die }, { preserveScroll: true });
    }

    function rollSave(row, skillId) {
        router.post(`/characters/${row.id}/rolls`, {
            skill_id: skillId,
            circumstance: circumstance[`c-${row.id}`] ?? 0,
            combatant_id: row.combatant_id,
        }, { preserveScroll: true });
    }

    function rollAttack(row, attack, heavy = false) {
        const [targetType, targetId] = (target || '').split(':');
        setFailedAttack(`c-${row.id}-${attack.kind}-${attack.item_id || attack.spell_id || attack.name}-${heavy ? 'heavy' : 'roll'}`);
        router.post(`/characters/${row.id}/attacks`, {
            kind: attack.kind,
            item_id: attack.item_id,
            spell_id: attack.spell_id,
            heavy,
            combatant_id: row.combatant_id,
            target_type: targetType || null,
            target_id: targetId || null,
            circumstance: circumstance[`c-${row.id}`] ?? 0,
        }, { preserveScroll: true });
    }

    function addToCombat(payload) {
        router.post(`/campaigns/${campaign.id}/combat`, payload, { preserveScroll: true });
    }

    function removeFromCombat(payload) {
        router.delete(`/campaigns/${campaign.id}/combat`, { data: payload, preserveScroll: true });
    }

    const canRoll = campaign.is_gm || (roster.characters || []).some((row) => row.can_open);

    const saveNames = ['Melee', 'Ranged', 'Endurance', 'Reflexes', 'Willpower'];
    const cardClass = (combatantId) => `flex items-start gap-4 border bg-panel p-4 ${Number(combatantId) === Number(active?.id) ? 'border-maroon' : 'border-line'}`;

    return (
        <div className="space-y-4">
            {canRoll && (
                <label className="flex flex-col justify-center gap-3 border border-maroon bg-maroon-deep px-5 py-3 sm:flex-row sm:items-center sm:gap-6">
                    <span className="shrink-0 text-xs tracking-[0.22em] text-parchment uppercase">Attack target</span>
                    <select className={`${inputClass} max-w-3xl normal-case`} value={target} onChange={(e) => setTarget(e.target.value)}>
                        <option value="">No target — roll only</option>
                        {targets.map((row) => (
                            <option key={`${row.type}-${row.id}`} value={`${row.type}:${row.id}`}>{row.name} · {row.wounds ?? '—'} wounds</option>
                        ))}
                    </select>
                </label>
            )}
            <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_22rem]">
            <section className="space-y-3">
                <h2 className="flex items-center gap-2 font-display text-2xl"><img src={art['player-characters']} alt="" className="h-8 w-8 shrink-0 object-contain" /> Player Characters</h2>
                {(roster.characters || []).map((row) => (
                    <article key={row.id} className={cardClass(row.combatant_id)}>
                        {row.portrait_url && <Portrait src={row.portrait_url} name={row.name} size="xl" />}
                        <div className="min-w-0 flex-1">
                            <div className="flex items-start justify-between gap-3">
                                <h3 className="font-display text-2xl leading-none">
                                    {row.can_open
                                        ? <Link href={`/characters/${row.id}`} className="hover:text-silver">{row.name}</Link>
                                        : row.name}
                                </h3>
                                <div className="flex shrink-0 items-center gap-2">
                                    {row.in_combat
                                        ? (campaign.is_gm
                                            ? <button type="button" className={`${inCombatClass} hover:bg-silver-green/10`} title="Remove from combat" onClick={() => removeFromCombat({ character_id: row.id })}>In combat</button>
                                            : <span className={inCombatClass}>In combat</span>)
                                        : campaign.is_gm && <button type="button" className={chipClass} onClick={() => addToCombat({ character_id: row.id })}>Add to combat</button>}
                                    {row.can_open && <button type="submit" form={`pc-${row.id}`} className={chipClass}>Save</button>}
                                </div>
                            </div>
                            <p className="mt-1 text-sm text-silver">{row.subtitle}</p>
                            {row.can_open ? (
                                <form id={`pc-${row.id}`} className="mt-3 grid grid-cols-3 gap-x-4 gap-y-3" onSubmit={(e) => { e.preventDefault(); router.patch(`/characters/${row.id}`, { xp: campaign.is_gm ? Math.max(0, Number(e.target.xp?.value) || 0) : undefined, resources: { wounds: e.target.wounds.value, major_action: Math.max(0, Math.min(1, Number(e.target.major_action.value) || 0)), minor_action: Math.max(0, Math.min(2, Number(e.target.minor_action.value) || 0)), luck: Math.max(0, Math.min(row.luck_max ?? 0, Number(e.target.luck.value) || 0)), stamina: Math.max(0, Math.min(row.stamina_max ?? 0, Number(e.target.stamina.value) || 0)), sorcerous_power: Math.max(0, Math.min(row.power_max ?? 0, Number(e.target.power.value) || 0)), focus: Math.max(0, Math.min(row.focus_max ?? 0, Number(e.target.focus.value) || 0)) }, suffering: Math.max(0, Math.min(2, Number(e.target.suffering.value) || 0)) }, { preserveScroll: true }); }}>
                                    <StatField icon={art['major-action']} label="Major Action">
                                        <input key={`${row.id}-major-${row.major_action ?? 1}`} name="major_action" className={statInput} type="number" min="0" max="1" defaultValue={row.major_action ?? 1} />
                                        <span>/ 1</span>
                                    </StatField>
                                    <StatField icon={art['minor-action']} label="Minor Action">
                                        <input key={`${row.id}-minor-${row.minor_action ?? 2}`} name="minor_action" className={statInput} type="number" min="0" max="2" defaultValue={row.minor_action ?? 2} />
                                        <span>/ 2</span>
                                    </StatField>
                                    <StatField icon={art.wounds} label="Wounds">
                                        <input key={`${row.id}-wounds-${row.wounds ?? 0}`} name="wounds" className={statInput} type="number" min="0" defaultValue={row.wounds ?? 0} />
                                        <span>/ {row.max ?? row.wounds ?? '—'}</span>
                                    </StatField>
                                    <StatField icon={art.suffering} label="Suffering">
                                        <input key={`${row.id}-suffering-${row.suffering ?? 0}`} name="suffering" className={statInput} type="number" min="0" max="2" defaultValue={row.suffering ?? 0} />
                                        <span>/ 2</span>
                                    </StatField>
                                    <StatField icon={art.stamina} label="Stamina">
                                        <input key={`${row.id}-stamina-${row.stamina ?? 0}`} name="stamina" className={statInput} type="number" min="0" max={row.stamina_max ?? 0} defaultValue={row.stamina ?? 0} />
                                        <span>/ {row.stamina_max ?? 0}</span>
                                    </StatField>
                                    <StatField icon={art.power} label="Power">
                                        <input key={`${row.id}-power-${row.power ?? 0}`} name="power" className={statInput} type="number" min="0" max={row.power_max ?? 0} defaultValue={row.power ?? 0} />
                                        <span>/ {row.power_max ?? 0}</span>
                                    </StatField>
                                    <StatField icon={art.focus} label="Focus">
                                        <input key={`${row.id}-focus-${row.focus ?? 0}`} name="focus" className={statInput} type="number" min="0" max={row.focus_max ?? 0} defaultValue={row.focus ?? 0} />
                                        <span>/ {row.focus_max ?? 0}</span>
                                    </StatField>
                                    <StatField icon={art.init} label="Init"><span>{signed(row.initiative)}</span></StatField>
                                    <StatField icon={art.armor} label="Armor"><span>{row.armor ?? 0}%</span></StatField>
                                    <StatField icon={art.luck} label="Luck">
                                        <input key={`${row.id}-luck-${row.luck ?? 0}`} name="luck" className={statInput} type="number" min="0" max={row.luck_max ?? 0} defaultValue={row.luck ?? 0} />
                                        <span>/ {row.luck_max ?? 0}</span>
                                    </StatField>
                                    {campaign.is_gm && (
                                        <StatField label="XP">
                                            <input key={`${row.id}-xp-${row.xp ?? 0}`} name="xp" className={statInput} type="number" min="0" defaultValue={row.xp ?? 0} />
                                        </StatField>
                                    )}
                                </form>
                            ) : (
                                <div className="mt-3 grid grid-cols-3 gap-x-4 gap-y-3">
                                    <StatField icon={art.init} label="Init"><span>{signed(row.initiative)}</span></StatField>
                                    <StatField icon={art.armor} label="Armor"><span>{row.armor ?? 0}%</span></StatField>
                                    <StatField icon={art.luck} label="Luck"><span>{row.luck ?? 0}/{row.luck_max ?? 0}</span></StatField>
                                    <StatField icon={art.wounds} label="Wounds"><span>{row.wounds ?? '—'}/{row.max ?? '—'}</span></StatField>
                                    <StatField icon={art.stamina} label="Stamina"><span>{row.stamina ?? 0}/{row.stamina_max ?? 0}</span></StatField>
                                    <StatField icon={art.power} label="Power"><span>{row.power ?? 0}/{row.power_max ?? 0}</span></StatField>
                                    <StatField icon={art.focus} label="Focus"><span>{row.focus ?? 0}/{row.focus_max ?? 0}</span></StatField>
                                </div>
                            )}
                            <ConditionPicker
                                applied={row.conditions || []}
                                catalog={conditions}
                                canEdit={row.can_open}
                                onAdd={(conditionId) => addCharacterCondition(row.id, conditionId)}
                                onRemove={(id) => removeCharacterCondition(row.id, id)}
                            />
                            {row.can_open && (
                                <>
                                    <CircumstanceSelect value={circumstance[`c-${row.id}`] ?? 0} onChange={(value) => setCircumstance((current) => ({ ...current, [`c-${row.id}`]: value }))} />
                                    <div className="mt-3 grid grid-cols-3 gap-2">
                                        {(row.saves || []).map((save) => (
                                            <button key={save.id} type="button" className={chipClass} onClick={() => rollSave(row, save.id)}>{rollLabel(save.name, save.tier + (circumstance[`c-${row.id}`] ?? 0))}</button>
                                        ))}
                                    </div>
                                    {(row.skills || []).length > 0 && (
                                        <div className="mt-2 flex flex-wrap gap-2">
                                            {(row.skills || []).map((skill) => (
                                                <button key={skill.id} type="button" className={chipClass} onClick={() => rollSave(row, skill.id)}>{rollLabel(skill.name, skill.tier + (circumstance[`c-${row.id}`] ?? 0))}</button>
                                            ))}
                                        </div>
                                    )}
                                    <DiceButtons onRoll={(die) => rollDie({ character_id: row.id }, die)} />
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        {(row.attacks || []).map((attack) => {
                                            const key = `c-${row.id}-${attack.kind}-${attack.item_id || attack.spell_id || attack.name}-roll`;
                                            return (
                                                <span key={key} className="inline-flex flex-col">
                                                    <button type="button" className={attackLinkClass} onClick={() => rollAttack(row, attack)}>{attackLine(attack.name, attack.tier == null ? null : attack.tier + (circumstance[`c-${row.id}`] ?? 0), attack.save)}</button>
                                                    {actionError && failedAttack === key && <span className="mt-1 text-xs text-maroon">{actionError}</span>}
                                                </span>
                                            );
                                        })}
                                        {(row.attacks || []).filter((attack) => attack.heavy).map((attack) => {
                                            const key = `c-${row.id}-${attack.kind}-${attack.item_id || attack.spell_id || attack.name}-heavy`;
                                            return (
                                                <span key={key} className="inline-flex flex-col">
                                                    <button type="button" className={attackLinkClass} onClick={() => rollAttack(row, attack, true)}>{attackLine(`${attack.name}, heavy`, attack.tier == null ? null : attack.tier + (circumstance[`c-${row.id}`] ?? 0), attack.save)}</button>
                                                    {actionError && failedAttack === key && <span className="mt-1 text-xs text-maroon">{actionError}</span>}
                                                </span>
                                            );
                                        })}
                                    </div>
                                    {lastRoll?.character_id === row.id && lastRoll.summary && (
                                        <p className="mt-3 text-sm"><LogText text={lastRoll.summary} /></p>
                                    )}
                                </>
                            )}
                        </div>
                    </article>
                ))}
                {(roster.characters || []).length === 0 && <p className="text-sm text-silver">No characters in this campaign yet.</p>}
            </section>
            <section className="space-y-3">
                <h2 className="flex items-center gap-2 font-display text-2xl"><img src={art.npcs} alt="" className="h-8 w-8 shrink-0 object-contain" /> Non-Player Characters</h2>
                {!campaign.is_gm && (roster.creatures || []).filter((creature) => creature.in_combat).map((creature) => (
                    <article key={creature.id} className={cardClass(creature.combatant_id)}>
                        {creature.portrait_url && <Portrait src={creature.portrait_url} name={creature.name} size="xl" />}
                        <div className="min-w-0 flex-1">
                            <div className="flex items-start justify-between gap-3">
                                <h3 className="font-display text-2xl leading-none">{creature.name}</h3>
                                <span className={inCombatClass}>In combat</span>
                            </div>
                            <p className="mt-1 text-sm text-silver">{creature.template}</p>
                            <div className="mt-3 grid grid-cols-3 gap-x-4 gap-y-3">
                                <StatField icon={art.init} label="Init"><span>{signed(creature.initiative)}</span></StatField>
                                <StatField icon={art.armor} label="Armor"><span>{creature.armor ?? 0}%</span></StatField>
                                <StatField icon={art.wounds} label="Wounds"><span>{creature.wounds ?? '—'}/{creature.max ?? '—'}</span></StatField>
                                <StatField icon={art.suffering} label="Suffering"><span>{creature.suffering ?? 0}/2</span></StatField>
                                <StatField icon={art.stamina} label="Stamina"><span>{creature.stamina ?? 0}/{creature.stamina_max ?? 0}</span></StatField>
                                <StatField icon={art.power} label="Power"><span>{creature.power ?? 0}/{creature.power_max ?? 0}</span></StatField>
                                <StatField icon={art.focus} label="Focus"><span>{creature.focus ?? 0}/{creature.focus_max ?? 0}</span></StatField>
                            </div>
                            <ConditionPicker applied={creature.conditions || []} catalog={conditions} />
                        </div>
                    </article>
                ))}
                {!campaign.is_gm && (roster.creatures || []).filter((creature) => creature.in_combat).length === 0 && <p className="text-sm text-silver">No non-player characters are in combat.</p>}
                {campaign.is_gm && (<>
                {(roster.creatures || []).map((creature) => {
                    const primary = (creature.checks || []).filter((check) => saveNames.includes(check.name));
                    const other = (creature.checks || []).filter((check) => !saveNames.includes(check.name));
                    return (
                    <article key={creature.id} className={cardClass(creature.combatant_id)}>
                        {creature.portrait_url && <Portrait src={creature.portrait_url} name={creature.name} size="xl" />}
                        <div className="min-w-0 flex-1">
                        <div className="flex items-start justify-between gap-3">
                            <h3 className="font-display text-2xl leading-none">{creature.name}</h3>
                            <div className="flex shrink-0 flex-col items-end gap-1">
                                <div className="flex items-center gap-2">
                                    {creature.in_combat
                                        ? <button type="button" className={`${inCombatClass} hover:bg-silver-green/10`} title="Remove from combat" onClick={() => removeFromCombat({ creature_instance_id: creature.id })}>In combat</button>
                                        : <button type="button" className={chipClass} onClick={() => addToCombat({ creature_instance_id: creature.id })}>Add to combat</button>}
                                    <button type="submit" form={`npc-${creature.id}`} className={chipClass}>Save</button>
                                </div>
                                <button type="button" className="text-xs text-silver underline underline-offset-2 hover:text-parchment" onClick={() => router.delete(`/npc-instances/${creature.id}`, { preserveScroll: true })}>Remove</button>
                            </div>
                        </div>
                        <p className="mt-1 text-sm text-silver">{creature.template}</p>
                        <form id={`npc-${creature.id}`} className="mt-3 grid grid-cols-3 gap-x-4 gap-y-3" onSubmit={(e) => { e.preventDefault(); router.patch(`/npc-instances/${creature.id}`, { name: creature.name, current_wounds: e.target.wounds.value, suffering: Math.max(0, Math.min(2, Number(e.target.suffering.value) || 0)), major_action: Math.max(0, Math.min(1, Number(e.target.major_action.value) || 0)), minor_action: Math.max(0, Math.min(2, Number(e.target.minor_action.value) || 0)), stamina: Math.max(0, Math.min(creature.stamina_max ?? 0, Number(e.target.stamina.value) || 0)), power: Math.max(0, Math.min(creature.power_max ?? 0, Number(e.target.power.value) || 0)), focus: Math.max(0, Math.min(creature.focus_max ?? 0, Number(e.target.focus.value) || 0)) }, { preserveScroll: true }); }}>
                            <StatField icon={art['major-action']} label="Major Action">
                                <input key={`${creature.id}-major-${creature.major_action ?? 1}`} name="major_action" className={statInput} type="number" min="0" max="1" defaultValue={creature.major_action ?? 1} />
                                <span>/ 1</span>
                            </StatField>
                            <StatField icon={art['minor-action']} label="Minor Action">
                                <input key={`${creature.id}-minor-${creature.minor_action ?? 2}`} name="minor_action" className={statInput} type="number" min="0" max="2" defaultValue={creature.minor_action ?? 2} />
                                <span>/ 2</span>
                            </StatField>
                            <StatField icon={art.wounds} label="Wounds">
                                <input key={`${creature.id}-wounds-${creature.wounds ?? 0}`} name="wounds" className={statInput} type="number" min="0" defaultValue={creature.wounds ?? 0} />
                                <span>/ {creature.max ?? creature.wounds}</span>
                            </StatField>
                            <StatField icon={art.suffering} label="Suffering">
                                <input key={`${creature.id}-suffering-${creature.suffering ?? 0}`} name="suffering" className={statInput} type="number" min="0" max="2" defaultValue={creature.suffering ?? 0} />
                                <span>/ 2</span>
                            </StatField>
                            <StatField icon={art.stamina} label="Stamina">
                                <input key={`${creature.id}-stamina-${creature.stamina ?? 0}`} name="stamina" className={statInput} type="number" min="0" max={creature.stamina_max ?? 0} defaultValue={creature.stamina ?? 0} />
                                <span>/ {creature.stamina_max ?? 0}</span>
                            </StatField>
                            <StatField icon={art.power} label="Power">
                                <input key={`${creature.id}-power-${creature.power ?? 0}`} name="power" className={statInput} type="number" min="0" max={creature.power_max ?? 0} defaultValue={creature.power ?? 0} />
                                <span>/ {creature.power_max ?? 0}</span>
                            </StatField>
                            <StatField icon={art.focus} label="Focus">
                                <input key={`${creature.id}-focus-${creature.focus ?? 0}`} name="focus" className={statInput} type="number" min="0" max={creature.focus_max ?? 0} defaultValue={creature.focus ?? 0} />
                                <span>/ {creature.focus_max ?? 0}</span>
                            </StatField>
                            <StatField icon={art.init} label="Init"><span>{signed(creature.initiative)}</span></StatField>
                            <StatField icon={art.armor} label="Armor"><span>{creature.armor ?? 0}%</span></StatField>
                        </form>
                        <ConditionPicker
                            applied={creature.conditions || []}
                            catalog={conditions}
                            canEdit
                            onAdd={(conditionId) => addCreatureCondition(creature.id, conditionId)}
                            onRemove={(id) => removeCreatureCondition(creature.id, id)}
                        />
                        <CircumstanceSelect value={circumstance[`n-${creature.id}`] ?? 0} onChange={(value) => setCircumstance((current) => ({ ...current, [`n-${creature.id}`]: value }))} />
                        {primary.length > 0 && (
                            <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                                {primary.map((check) => (
                                    <button key={check.name} type="button" className={chipClass} onClick={() => rollCheck(creature, check.name)}>{rollLabel(check.name, adjustedAttackTier(check.tier, creature.suffering, circumstance[`n-${creature.id}`] ?? 0))}</button>
                                ))}
                            </div>
                        )}
                        {(other.length > 0 || (creature.skills || []).length > 0) && (
                            <div className="mt-2 flex flex-wrap gap-2">
                                {other.map((check) => (
                                    <button key={check.name} type="button" className={chipClass} onClick={() => rollCheck(creature, check.name)}>{rollLabel(check.name, adjustedAttackTier(check.tier, creature.suffering, circumstance[`n-${creature.id}`] ?? 0))}</button>
                                ))}
                                {(creature.skills || []).map((skill) => (
                                    <button key={skill.name} type="button" className={chipClass} onClick={() => rollCheck(creature, skill.name)}>{rollLabel(skill.name, adjustedAttackTier(skill.tier, creature.suffering, circumstance[`n-${creature.id}`] ?? 0))}</button>
                                ))}
                            </div>
                        )}
                        <DiceButtons onRoll={(die) => rollDie({ creature_instance_id: creature.id }, die)} />
                        <div className="mt-3 flex flex-wrap gap-2">
                            {(creature.attacks || []).filter((attack) => attack.resolution !== 'save').map((attack) => {
                                const key = `n-${creature.id}-${attack.name}`;
                                return (
                                    <span key={key} className="inline-flex flex-col">
                                        <button type="button" className={attackLinkClass} onClick={() => roll(creature, attack.name)}>{attackLine(attack.name, attack.tier == null ? null : adjustedAttackTier(attack.tier, creature.suffering, circumstance[`n-${creature.id}`] ?? 0), null)}</button>
                                        {actionError && failedAttack === key && <span className="mt-1 text-xs text-maroon">{actionError}</span>}
                                    </span>
                                );
                            })}
                        </div>
                        {lastRoll?.npc_id === creature.id && (lastRoll.attack || lastRoll.die) && (
                            <p className="mt-3 text-sm"><LogText text={lastRoll.summary} /></p>
                        )}
                        {lastRoll?.npc_id === creature.id && !lastRoll.attack && !lastRoll.die && (
                            <p className="mt-3 text-sm">{lastRoll.skill}: {lastRoll.display} {lastRoll.modifier ? `${lastRoll.modifier}. ` : ''}{lastRoll.success ? 'success' : 'failure'}{lastRoll.critical ? ', critical' : ''}.</p>
                        )}
                        </div>
                    </article>
                    );
                })}
                {(roster.creatures || []).length === 0 && <p className="text-sm text-silver">Copy an NPC from the tome into this campaign to track them here.</p>}
                </>)}
            </section>
            <Combat campaign={campaign} encounter={encounter} active={active} fight={fight} roster={roster} split />
            </div>
            <p className="text-center text-xs text-silver">Tip: Click Save to persist changes to this combat.</p>
        </div>
    );
}

function DiceButtons({ onRoll }) {
    return (
        <div className="mt-3 flex flex-wrap gap-2">
            <button type="button" className={chipClass} onClick={() => onRoll('percentile')}>Percentile</button>
            <button type="button" className={chipClass} onClick={() => onRoll('2d6')}>2d6</button>
            <button type="button" className={chipClass} onClick={() => onRoll('1d6')}>1d6</button>
        </div>
    );
}

const chipClass = 'border border-line px-2 py-1.5 text-center text-xs text-silver hover:border-silver hover:text-parchment';
const inCombatClass = 'border border-silver-green px-1.5 py-0.5 text-[10px] tracking-[0.14em] text-silver-green uppercase';
const statInput = 'w-10 border border-line bg-ink px-1 py-0.5 text-center text-sm text-parchment';
const attackLinkClass = 'border border-parchment px-2 py-1.5 text-center text-xs text-silver hover:border-silver hover:text-parchment';

function signed(value) {
    const number = Number(value) || 0;
    return number >= 0 ? `+${number}` : String(number);
}

function attackLine(name, tier, save) {
    if (save) return `${name} · ${save} save`;
    const chance = tier == null ? null : chanceLabel(tier);
    return chance ? `${name} · ${chance}` : name;
}

function rollLabel(name, tier) {
    const chance = chanceLabel(tier);
    if (!chance) return name;
    return `${name} · ${chance.replace(' to hit', '')}`;
}

function StatField({ icon, label, children }) {
    return (
        <label className="flex min-w-0 flex-col gap-1 text-xs text-silver">
            <span className="flex items-center gap-1 leading-tight">
                {icon && <img src={icon} alt="" className="h-4 w-4 shrink-0" />}
                <span>{label}</span>
            </span>
            <span className="flex items-center gap-1 text-parchment">{children}</span>
        </label>
    );
}

function ScrollIcon() {
    return (
        <svg viewBox="0 0 24 24" className="h-5 w-5 text-parchment" aria-hidden="true">
            <path d="M7 5h10a2 2 0 012 2v12H8a2 2 0 01-2-2V7a2 2 0 00-2-2h3z" fill="none" stroke="currentColor" strokeWidth="1.5" />
            <path d="M10 9h6M10 13h6" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
        </svg>
    );
}
