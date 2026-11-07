import { Form } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { RouteFormDefinition } from '@/wayfinder';

export default function ProjectNameDialog({
    trigger,
    open,
    onOpenChange,
    title,
    description,
    submitLabel,
    form,
    name = '',
}: {
    trigger?: ReactNode;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    title: string;
    description: string;
    submitLabel: string;
    form: RouteFormDefinition<'post'>;
    name?: string;
}) {
    const [internalOpen, setInternalOpen] = useState(false);
    const isOpen = open ?? internalOpen;
    const setOpen = onOpenChange ?? setInternalOpen;

    return (
        <Dialog open={isOpen} onOpenChange={setOpen}>
            {trigger && <DialogTrigger asChild>{trigger}</DialogTrigger>}
            <DialogContent>
                <Form
                    {...form}
                    onSuccess={() => setOpen(false)}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>{title}</DialogTitle>
                                <DialogDescription>
                                    {description}
                                </DialogDescription>
                            </DialogHeader>
                            <div className="space-y-2">
                                <Label htmlFor="project-name">Name</Label>
                                <Input
                                    id="project-name"
                                    name="name"
                                    defaultValue={name}
                                    maxLength={60}
                                    autoComplete="off"
                                    autoFocus
                                    required
                                />
                                <InputError message={errors.name} />
                            </div>
                            <DialogFooter>
                                <Button type="submit" disabled={processing}>
                                    {submitLabel}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
