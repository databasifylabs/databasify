import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { FilterOption } from '@/types';

const ALL = '__all__';

export default function FilterSelect({
    label,
    allLabel,
    value,
    options,
    onChange,
    className,
}: {
    label: string;
    allLabel: string;
    value: string | null;
    options: FilterOption[];
    onChange: (value: string | null) => void;
    className?: string;
}) {
    return (
        <Select
            value={value ?? ALL}
            onValueChange={(next) => onChange(next === ALL ? null : next)}
        >
            <SelectTrigger
                aria-label={label}
                className={cn('w-full sm:w-44', className)}
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={ALL}>{allLabel}</SelectItem>
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
