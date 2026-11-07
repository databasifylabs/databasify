import { FolderInput } from 'lucide-react';
import type { ComponentProps } from 'react';
import MoveToProjectDialog from '@/components/projects/move-to-project-dialog';
import { Button } from '@/components/ui/button';

export default function MoveToProjectButton(
    props: Omit<
        ComponentProps<typeof MoveToProjectDialog>,
        'trigger' | 'open' | 'onOpenChange'
    >,
) {
    return (
        <MoveToProjectDialog
            {...props}
            trigger={
                <Button
                    variant="ghost"
                    size="sm"
                    aria-label={`Move ${props.resource.name} to another project`}
                >
                    <FolderInput /> Move
                </Button>
            }
        />
    );
}
