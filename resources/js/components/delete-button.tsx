import { Trash2 } from 'lucide-react';
import type { ComponentProps } from 'react';
import { Button } from '@/components/ui/button';

export default function DeleteButton(props: ComponentProps<typeof Button>) {
    return (
        <Button
            variant="ghost"
            size="icon"
            className="text-destructive hover:text-destructive"
            {...props}
        >
            <Trash2 />
        </Button>
    );
}
