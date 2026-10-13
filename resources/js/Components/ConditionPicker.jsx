import { router } from '@inertiajs/react';
import { inputClass } from './Shell';

export default function ConditionPicker({ applied = [], catalog = [], canEdit = false, onAdd, onRemove }) {
    const active = applied.filter((row) => row.key !== 'suffering' && row.name !== 'Suffering');
    const available = catalog.filter((row) => row.key !== 'suffering' && !active.some((item) => Number(item.condition_id) === Number(row.id)));

    return (
        <div className="mt-3">
            <p className="text-xs tracking-widest text-silver uppercase">Conditions</p>
            <div className="mt-2 flex flex-wrap gap-2">
                {active.length === 0 && <span className="text-xs text-silver">None</span>}
                {active.map((row) => canEdit ? (
                    <button key={row.id} type="button" title={row.detail || 'Remove'} className="border border-maroon px-2 py-1 text-xs text-parchment hover:bg-maroon-deep" onClick={() => onRemove(row.id)}>{row.name} ×</button>
                ) : (
                    <span key={row.id} title={row.detail || ''} className="border border-maroon px-2 py-1 text-xs text-parchment">{row.name}</span>
                ))}
            </div>
            {canEdit && (
                <select className={`${inputClass} mt-2`} value="" aria-label="Add a condition" onChange={(event) => { if (event.target.value) onAdd(event.target.value); }}>
                    <option value="">Add a condition</option>
                    {available.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}
                </select>
            )}
        </div>
    );
}

export function addCharacterCondition(characterId, conditionId) {
    router.post(`/characters/${characterId}/conditions`, { condition_id: conditionId }, { preserveScroll: true });
}

export function removeCharacterCondition(characterId, id) {
    router.delete(`/characters/${characterId}/conditions/${id}`, { preserveScroll: true });
}

export function addCreatureCondition(creatureId, conditionId) {
    router.post(`/npc-instances/${creatureId}/conditions`, { condition_id: conditionId }, { preserveScroll: true });
}

export function removeCreatureCondition(creatureId, id) {
    router.delete(`/npc-instances/${creatureId}/conditions/${id}`, { preserveScroll: true });
}
