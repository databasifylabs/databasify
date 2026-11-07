import { Form } from '@inertiajs/react';
import { FolderInput } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import MoveController from '@/actions/App/Http/Controllers/Projects/MoveController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useMoveTargets } from '@/hooks/use-move-targets';
import type { MovableResourceType } from '@/types';

type MovableResource = {
    type: MovableResourceType;
    id: string;
    name: string;
};

export default function MoveToProjectDialog({
    resource,
    description,
    open,
    onOpenChange,
    trigger,
}: {
    resource: MovableResource;
    description?: string;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    trigger?: ReactNode;
}) {
    const targets = useMoveTargets();
    const [internalOpen, setInternalOpen] = useState(false);
    const [target, setTarget] = useState(targets[0]?.id ?? '');
    const isOpen = open ?? internalOpen;
    const setOpen = onOpenChange ?? setInternalOpen;

    if (targets.length === 0) {
        return null;
    }

    return (
        <Dialog open={isOpen} onOpenChange={setOpen}>
            {trigger && <DialogTrigger asChild>{trigger}</DialogTrigger>}
            <DialogContent>
                <Form
                    {...MoveController.store.form()}
                    onSuccess={() => setOpen(false)}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>Move {resource.name}</DialogTitle>
                                <DialogDescription>
                                    {description ??
                                        'It leaves this project and shows up in the one you pick.'}
                                </DialogDescription>
                            </DialogHeader>
                            <input
                                type="hidden"
                                name="type"
                                value={resource.type}
                            />
                            <input
                                type="hidden"
                                name="id"
                                value={resource.id}
                            />
                            <input
                                type="hidden"
                                name="project_id"
                                value={target}
                            />
                            <div className="space-y-2">
                                <Label htmlFor="move-target-project">
                                    Project
                                </Label>
                                <Select
                                    value={target}
                                    onValueChange={setTarget}
                                >
                                    <SelectTrigger
                                        id="move-target-project"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {targets.map((project) => (
                                            <SelectItem
                                                key={project.id}
                                                value={project.id}
                                            >
                                                {project.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError
                                    message={
                                        errors.project_id ??
                                        errors.id ??
                                        errors.type
                                    }
                                />
                            </div>
                            <DialogFooter>
                                <Button type="submit" disabled={processing}>
                                    <FolderInput />
                                    Move
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
