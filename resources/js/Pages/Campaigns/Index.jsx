import { useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import Shell, { buttonClass, inputClass, quietClass } from '../../Components/Shell';

export default function Index({ campaigns, publicCampaigns = [], characters = [] }) {
    const gm = usePage().props.can?.gm;
    const [joining, setJoining] = useState(null);
    const form = useForm({ name: '', description: '', is_public: false, password: '' });
    return (
        <Shell title="Campaigns">
            <div className="grid gap-3">
                {campaigns.map((campaign) => (
                    <div key={campaign.id} className="border border-line bg-panel px-4 py-3">
                        <div className="flex items-center justify-between gap-4">
                            <Link href={`/campaigns/${campaign.id}`} className="min-w-0 flex-1 hover:text-silver">
                                <div className="font-display text-3xl">{campaign.name}</div>
                                <div className="text-sm text-silver">{campaign.role} · {campaign.is_public ? 'public' : 'private'} · {campaign.description}</div>
                            </Link>
                            <div className="flex shrink-0 gap-2">
                                {campaign.can_add_character && (
                                    <button type="button" className={buttonClass} onClick={() => setJoining(joining === campaign.id ? null : campaign.id)}>Join</button>
                                )}
                                {campaign.can_delete && (
                                    <button type="button" className={quietClass} onClick={() => { if (window.confirm(`Delete ${campaign.name}?`)) router.delete(`/campaigns/${campaign.id}`); }}>Delete</button>
                                )}
                            </div>
                        </div>
                        {joining === campaign.id && <AddCharacter campaign={campaign} characters={characters} />}
                    </div>
                ))}
                {campaigns.length === 0 && <p className="text-sm text-silver">You have not joined a campaign yet.</p>}
            </div>
            <section className="mt-8">
                <h2 className="font-display text-2xl">Public campaigns</h2>
                <div className="mt-3 grid gap-3">
                    {publicCampaigns.map((campaign) => (
                        <Join key={campaign.id} campaign={campaign} characters={characters} />
                    ))}
                    {publicCampaigns.length === 0 && <p className="text-sm text-silver">No public campaigns are waiting.</p>}
                </div>
            </section>
            {gm && (
                <form onSubmit={(e) => { e.preventDefault(); form.post('/campaigns'); }} className="mt-8 max-w-lg space-y-3 border border-line p-4">
                    <h2 className="font-display text-2xl">Open a campaign</h2>
                    <input className={inputClass} placeholder="Name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                    <textarea className={inputClass} placeholder="What waits past the last watchfire" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                    <label className="block text-xs tracking-widest text-silver uppercase">Who can find it
                        <select className={`${inputClass} mt-1 normal-case`} value={form.data.is_public ? 'public' : 'private'} onChange={(e) => form.setData('is_public', e.target.value === 'public')}>
                            <option value="private">Private</option>
                            <option value="public">Public</option>
                        </select>
                    </label>
                    <input className={inputClass} type="password" placeholder="Password (optional)" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                    <button className={buttonClass}>Create</button>
                </form>
            )}
        </Shell>
    );
}

function Join({ campaign, characters }) {
    const [open, setOpen] = useState(false);
    return (
        <div className="border border-line bg-panel px-4 py-3">
            <div className="flex items-center justify-between gap-4">
                <div className="min-w-0">
                    <div className="font-display text-3xl">{campaign.name}</div>
                    <div className="text-sm text-silver">{campaign.description}{campaign.has_password ? ' · password required' : ''}</div>
                </div>
                <button type="button" className={buttonClass} onClick={() => setOpen((current) => !current)}>Join</button>
            </div>
            {open && <AddCharacter campaign={campaign} characters={characters} />}
        </div>
    );
}

function AddCharacter({ campaign, characters }) {
    const form = useForm({ character_id: characters[0]?.id || '', password: '' });
    return (
        <form onSubmit={(event) => { event.preventDefault(); form.post(`/campaigns/${campaign.id}/join`); }} className="mt-3 flex flex-wrap items-end gap-2 border-t border-line pt-3">
            <label className="text-xs tracking-widest text-silver uppercase">Character
                <select className={`${inputClass} mt-1 normal-case`} value={form.data.character_id} onChange={(e) => form.setData('character_id', e.target.value)}>
                    {characters.length === 0 && <option value="">No free character</option>}
                    {characters.map((character) => <option key={character.id} value={character.id}>{character.name}</option>)}
                </select>
            </label>
            {campaign.has_password && (
                <label className="text-xs tracking-widest text-silver uppercase">Password
                    <input className={`${inputClass} mt-1`} type="password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                </label>
            )}
            <button className={buttonClass} disabled={characters.length === 0}>Add character</button>
            {form.errors.password && <p className="w-full text-sm text-maroon">{form.errors.password}</p>}
            {form.errors.character_id && <p className="w-full text-sm text-maroon">{form.errors.character_id}</p>}
        </form>
    );
}
