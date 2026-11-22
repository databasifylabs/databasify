import { Form } from '@inertiajs/react';
import { useState } from 'react';
import ApiKeyController from '@/actions/App/Http/Controllers/ApiKeys/ApiKeyController';
import AccessFields from '@/components/api-keys/access-fields';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import type { AccessAreaOption } from '@/types';

const expiries = [
    { value: '30', label: '30 days' },
    { value: '90', label: '90 days' },
    { value: '365', label: '1 year' },
    { value: 'never', label: 'Never' },
];

export default function CreateApiKeySheet({
    open,
    onOpenChange,
    areas,
    projects,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    areas: AccessAreaOption[];
    projects: { id: string; name: string }[];
}) {
    const [expiry, setExpiry] = useState('90');

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="w-full gap-0 sm:max-w-md">
                <Form
                    {...ApiKeyController.store.form()}
                    resetOnSuccess
                    onSuccess={() => onOpenChange(false)}
                    className="flex h-full flex-col"
                >
                    {({ processing, errors }) => (
                        <>
                            <SheetHeader>
                                <SheetTitle>Create API key</SheetTitle>
                                <SheetDescription>
                                    Pick what this key can do and in which
                                    projects.
                                </SheetDescription>
                            </SheetHeader>

                            <div className="min-h-0 flex-1 space-y-6 overflow-y-auto px-4 pb-6">
                                <div className="grid gap-2">
                                    <Label htmlFor="key-name">Name</Label>
                                    <Input
                                        id="key-name"
                                        name="name"
                                        placeholder="staging-ci"
                                        autoComplete="off"
                                        required
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label>Expires</Label>
                                    <input
                                        type="hidden"
                                        name="expiry"
                                        value={expiry}
                                    />
                                    <ToggleGroup
                                        type="single"
                                        variant="outline"
                                        value={expiry}
                                        onValueChange={(value) =>
                                            value && setExpiry(value)
                                        }
                                    >
                                        {expiries.map((option) => (
                                            <ToggleGroupItem
                                                key={option.value}
                                                value={option.value}
                                                className="flex-1"
                                            >
                                                {option.label}
                                            </ToggleGroupItem>
                                        ))}
                                    </ToggleGroup>
                                    <InputError message={errors.expiry} />
                                </div>

                                <AccessFields
                                    areas={areas}
                                    projects={projects}
                                    errors={errors}
                                />
                            </div>

                            <SheetFooter className="flex-row justify-end border-t">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => onOpenChange(false)}
                                >
                                    Cancel
                                </Button>
                                <Button disabled={processing}>
                                    Create API key
                                </Button>
                            </SheetFooter>
                        </>
                    )}
                </Form>
            </SheetContent>
        </Sheet>
    );
}
