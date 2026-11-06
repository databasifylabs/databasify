import { Form } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { RouteFormDefinition } from '@/wayfinder';

export type SecretField = { name: string; label: string; secret?: boolean };

export default function EditAccountDialog({
    name,
    form,
    secrets,
    description,
}: {
    name: string;
    form: RouteFormDefinition<'post'>;
    secrets: SecretField[];
    description: string;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm" aria-label={`Edit ${name}`}>
                    <Pencil /> Edit
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Edit {name}</DialogTitle>
                <DialogDescription>{description}</DialogDescription>
                <Form
                    {...form}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    resetOnSuccess
                    className="space-y-5"
                >
                    {({ processing, errors }) => {
                        const messages: Record<string, string | undefined> =
                            errors;

                        return (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="account-name">Name</Label>
                                    <Input
                                        id="account-name"
                                        name="name"
                                        defaultValue={name}
                                        autoComplete="off"
                                    />
                                    <InputError message={messages.name} />
                                </div>
                                {secrets.map((secret) => (
                                    <div
                                        key={secret.name}
                                        className="grid gap-2"
                                    >
                                        <Label htmlFor={secret.name}>
                                            New {secret.label.toLowerCase()}
                                        </Label>
                                        <Input
                                            id={secret.name}
                                            name={secret.name}
                                            type={
                                                secret.secret === false
                                                    ? 'text'
                                                    : 'password'
                                            }
                                            autoComplete="off"
                                            placeholder="Leave blank to keep the current one"
                                            className="font-mono"
                                        />
                                        <InputError
                                            message={
                                                messages[
                                                    secret.name.replace(
                                                        /\[(\w+)\]/g,
                                                        '.$1',
                                                    )
                                                ]
                                            }
                                        />
                                    </div>
                                ))}
                                <InputError message={messages.credentials} />
                                <DialogFooter className="gap-2">
                                    <DialogClose asChild>
                                        <Button variant="secondary">
                                            Cancel
                                        </Button>
                                    </DialogClose>
                                    <Button type="submit" disabled={processing}>
                                        Check and save
                                    </Button>
                                </DialogFooter>
                            </>
                        );
                    }}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
