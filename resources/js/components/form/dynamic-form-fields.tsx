import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import type { FormField } from '@/types';

export default function DynamicFormFields({
    fields,
    prefix,
    errors,
    onChange,
}: {
    fields: FormField[];
    prefix: string;
    errors: Record<string, string | undefined>;
    onChange?: (name: string, filled: boolean) => void;
}) {
    return (
        <>
            {fields.map((field) => {
                const id = `${prefix}-${field.name}`;
                const name = `${prefix}[${field.name}]`;
                const changed = (value: string) =>
                    onChange?.(field.name, value.trim() !== '');

                return (
                    <div key={field.name} className="space-y-1.5">
                        <Label htmlFor={id}>
                            {field.label}
                            {field.optional && (
                                <span className="font-normal text-muted-foreground">
                                    {' '}
                                    (optional)
                                </span>
                            )}
                        </Label>
                        {field.type === 'select' ? (
                            <Select
                                name={name}
                                defaultValue={field.default ?? undefined}
                                onValueChange={changed}
                            >
                                <SelectTrigger id={id} className="w-full">
                                    <SelectValue
                                        placeholder={
                                            field.placeholder ?? 'Choose one'
                                        }
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    {field.options.map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        ) : field.type === 'textarea' ? (
                            <Textarea
                                id={id}
                                name={name}
                                defaultValue={field.default ?? undefined}
                                placeholder={field.placeholder ?? undefined}
                                onChange={(event) =>
                                    changed(event.target.value)
                                }
                                className="font-mono"
                            />
                        ) : (
                            <Input
                                id={id}
                                name={name}
                                type={field.type}
                                autoComplete="off"
                                defaultValue={field.default ?? undefined}
                                placeholder={field.placeholder ?? undefined}
                                onChange={(event) =>
                                    changed(event.target.value)
                                }
                                className="font-mono"
                            />
                        )}
                        {field.description && (
                            <p className="text-xs text-muted-foreground">
                                {field.description}
                            </p>
                        )}
                        <InputError
                            message={errors[`${prefix}.${field.name}`]}
                        />
                    </div>
                );
            })}
            <InputError message={errors[prefix]} />
        </>
    );
}
