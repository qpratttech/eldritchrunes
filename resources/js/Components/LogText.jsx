import { usePage } from '@inertiajs/react';

export default function LogText({ text }) {
    const art = usePage().props.art || {};
    const parts = String(text).split(/(Major Action|Minor Action|Critical hit!|Hit!)/g);
    const icons = {
        'Major Action': art['major-action'],
        'Minor Action': art['minor-action'],
        'Critical hit!': art.damage,
        'Hit!': art.damage,
    };

    return parts.map((part, index) => {
        if (!icons[part]) {
            return <span key={index}>{part}</span>;
        }
        const icon = <img src={icons[part]} alt="" className="mx-0.5 inline-block h-3.5 w-3.5 align-[-2px]" />;
        const leading = part === 'Major Action' || part === 'Minor Action';

        return <span key={index}>{leading ? icon : null}{part}{leading ? null : icon}</span>;
    });
}
