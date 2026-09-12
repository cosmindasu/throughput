import type { ActivityItem } from '@/types/generated';

interface ActivityFeedProps {
    items: ActivityItem[];
}

const dateTimeFormatter = new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
});

/**
 * Ultimele 10 intrări din `activity_log` (FR-DEMO-01, §21.3). Faza 1
 * populează asta doar din seed-ul istoric — scrierea live vine în Faza 5
 * (plan-implementare.md §7.4) — componenta nu face nicio presupunere despre
 * sursă.
 */
export default function ActivityFeed({ items }: ActivityFeedProps) {
    if (items.length === 0) {
        return <p className="text-sm text-text-2">No recent activity yet.</p>;
    }

    return (
        <ul className="divide-y divide-border-soft">
            {items.map((item) => (
                <li key={item.id} className="flex items-center justify-between gap-4 py-2 text-sm">
                    <div className="min-w-0">
                        <p className="truncate text-text">{item.description}</p>
                        <p className="truncate text-xs text-text-3">{item.actor}</p>
                    </div>
                    <time dateTime={item.at} className="numeric shrink-0 text-xs text-text-3">
                        {dateTimeFormatter.format(new Date(item.at))}
                    </time>
                </li>
            ))}
        </ul>
    );
}
