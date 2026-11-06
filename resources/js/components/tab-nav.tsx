import type { LucideIcon } from 'lucide-react';
import { TabsList, TabsTrigger } from '@/components/ui/tabs';

export type TabSpec = { value: string; label: string; icon?: LucideIcon };

export default function TabNav({ tabs }: { tabs: TabSpec[] }) {
    return (
        <div className="-mx-4 overflow-x-auto border-b px-4 md:mx-0 md:px-0">
            <TabsList className="h-auto w-max justify-start gap-1 rounded-none bg-transparent p-0">
                {tabs.map(({ value, label, icon: Icon }) => (
                    <TabsTrigger
                        key={value}
                        value={value}
                        className="-mb-px flex-none rounded-none border-0 border-b-2 border-transparent px-3 py-2.5 data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:shadow-none dark:data-[state=active]:border-primary dark:data-[state=active]:bg-transparent"
                    >
                        {Icon && <Icon />}
                        {label}
                    </TabsTrigger>
                ))}
            </TabsList>
        </div>
    );
}
