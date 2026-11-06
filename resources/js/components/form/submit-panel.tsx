import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export default function SubmitPanel({
    name,
    onName,
    nameLabel,
    placeholder,
    helper = 'Must be unique. Lowercase letters, numbers and dashes.',
    lowercase = true,
    error,
    summary,
    missing,
    processing,
    action,
    icon: Icon,
}: {
    name: string;
    onName: (name: string) => void;
    nameLabel: string;
    placeholder: string;
    helper?: string;
    lowercase?: boolean;
    error?: string;
    summary: ReactNode;
    missing: string | null;
    processing: boolean;
    action: string;
    icon: LucideIcon;
}) {
    return (
        <section className="space-y-4 rounded-2xl border bg-muted/30 p-5 sm:p-6">
            <div className="grid grid-cols-1 gap-5 md:grid-cols-[minmax(0,20rem)_1fr] md:items-start">
                <div className="space-y-1.5">
                    <Label htmlFor="name">{nameLabel}</Label>
                    <Input
                        id="name"
                        name="name"
                        required
                        value={name}
                        onChange={(event) =>
                            onName(
                                lowercase
                                    ? event.target.value.toLowerCase()
                                    : event.target.value,
                            )
                        }
                        placeholder={placeholder}
                        className="bg-background"
                    />
                    <p className="text-xs text-muted-foreground">{helper}</p>
                    <InputError message={error} />
                </div>
                <div className="space-y-1 md:justify-self-end md:text-right">
                    {summary}
                </div>
            </div>
            <div className="flex flex-col-reverse items-stretch gap-3 border-t pt-4 sm:flex-row sm:items-center sm:justify-end">
                {missing && (
                    <span className="text-xs whitespace-nowrap text-muted-foreground">
                        {missing}
                    </span>
                )}
                <Button
                    size="lg"
                    disabled={processing || missing !== null}
                    className="sm:flex-none"
                >
                    <Icon />
                    {action}
                </Button>
            </div>
        </section>
    );
}
