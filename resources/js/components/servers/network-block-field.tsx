import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

const blocks = [
    { prefix: '24', servers: '254' },
    { prefix: '23', servers: '510' },
    { prefix: '22', servers: '1,022' },
    { prefix: '20', servers: '4,094' },
    { prefix: '16', servers: '65,534' },
];

export default function NetworkBlockField({ error }: { error?: string }) {
    return (
        <div className="space-y-1.5 md:max-w-xs">
            <Label htmlFor="network_block">Block size</Label>
            <Select name="network_block" defaultValue={blocks[0].prefix}>
                <SelectTrigger
                    id="network_block"
                    className="w-full bg-background"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {blocks.map((block) => (
                        <SelectItem key={block.prefix} value={block.prefix}>
                            /{block.prefix} · up to {block.servers} servers
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <InputError message={error} />
        </div>
    );
}
