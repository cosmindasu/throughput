import Icon, { type IconName } from '@/Components/Icon';
import { TONE, type Tone } from '@/lib/tone';

const SIZES = {
    sm: { box: 'size-6', icon: 14 },
    md: { box: 'size-8', icon: 16 },
    lg: { box: 'size-10', icon: 20 },
} as const;

interface ToneIconProps {
    tone: Tone;
    name: IconName;
    size?: keyof typeof SIZES;
    shape?: 'round' | 'square';
    className?: string;
}

/**
 * Iconul într-un chip tentat. DECORATIV prin construcție (`aria-hidden`), ca `Icon` și ca
 * `Icon`: eticheta scrisă stă mereu lângă el. Dacă un icon rămâne singurul purtător al
 * sensului (buton fără text), numele accesibil se dă pe BUTON, ca `sr-only`, nu aici.
 */
export default function ToneIcon({ tone, name, size = 'md', shape = 'round', className = '' }: ToneIconProps) {
    const { box, icon } = SIZES[size];

    return (
        <span
            aria-hidden="true"
            className={`inline-flex shrink-0 items-center justify-center ${box} ${shape === 'round' ? 'rounded-full' : 'rounded-md'} ${TONE[tone].chip} ${className}`}
        >
            <Icon name={name} size={icon} />
        </span>
    );
}
