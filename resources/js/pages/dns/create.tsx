import { Form, Head } from '@inertiajs/react';
import { LockKeyhole, Plus } from 'lucide-react';
import { useState } from 'react';
import DnsProviderController from '@/actions/App/Http/Controllers/Dns/DnsProviderController';
import BrandIcon from '@/components/brand-icon';
import { ChoiceRow, FormSection } from '@/components/form/choice';
import DynamicFormFields from '@/components/form/dynamic-form-fields';
import Instructions from '@/components/form/instructions';
import SubmitPanel from '@/components/form/submit-panel';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import type { DnsProviderType } from '@/types';

export default function CreateDnsProvider({
    types,
}: {
    types: DnsProviderType[];
}) {
    const [value, setValue] = useState(types[0]?.value ?? '');
    const [name, setName] = useState('');
    const [filled, setFilled] = useState<Record<string, boolean>>({});
    const type = types.find((entry) => entry.value === value);
    const unfilled = type?.form.find(
        (field) => !field.optional && !field.default && !filled[field.name],
    );
    const missing = unfilled
        ? `Fill in the ${unfilled.label}`
        : name === ''
          ? 'Name the account'
          : null;

    return (
        <>
            <Head title="Add DNS account" />
            <Form
                {...DnsProviderController.store.form()}
                className="mx-auto w-full max-w-5xl space-y-5 p-4 pb-10 md:p-6"
            >
                {({ processing, errors }) => (
                    <>
                        <Heading title="Add DNS account" />
                        <input type="hidden" name="type" value={value} />

                        <FormSection title="DNS provider">
                            <div
                                className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3"
                                role="radiogroup"
                            >
                                {types.map((entry) => (
                                    <ChoiceRow
                                        key={entry.value}
                                        selected={entry.value === value}
                                        onSelect={() => setValue(entry.value)}
                                    >
                                        <BrandIcon
                                            group="dns-providers"
                                            src={entry.icon}
                                        />
                                        <span className="font-medium">
                                            {entry.label}
                                        </span>
                                    </ChoiceRow>
                                ))}
                            </div>
                            <InputError message={errors.type} />
                        </FormSection>

                        {type && (
                            <FormSection
                                title="Access"
                                description="Databasify uses it to list your domains, and keep database records up to date."
                            >
                                <div className="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
                                    <div className="space-y-4">
                                        <DynamicFormFields
                                            fields={type.form}
                                            prefix="credentials"
                                            errors={errors}
                                            onChange={(key, isFilled) =>
                                                setFilled({
                                                    ...filled,
                                                    [key]: isFilled,
                                                })
                                            }
                                        />
                                        <div className="flex gap-2.5 rounded-xl border p-3 text-xs text-muted-foreground">
                                            <LockKeyhole className="mt-0.5 size-4 shrink-0 text-green-600" />
                                            <p>
                                                Checked with {type.label} before
                                                saving, then stored encrypted.
                                                Its domains are imported right
                                                away.
                                            </p>
                                        </div>
                                    </div>
                                    {type.instructions &&
                                        type.instructions.length > 0 && (
                                            <Instructions
                                                title={`Connect ${type.label}`}
                                                steps={type.instructions}
                                            />
                                        )}
                                </div>
                            </FormSection>
                        )}

                        <SubmitPanel
                            name={name}
                            onName={setName}
                            nameLabel="Account name"
                            placeholder={`${type?.label ?? 'DNS'} main`}
                            helper="How this account appears in Databasify."
                            lowercase={false}
                            error={errors.name}
                            processing={processing}
                            missing={missing}
                            action="Check and add"
                            icon={Plus}
                            summary={
                                type && (
                                    <div className="flex items-center gap-3 md:flex-row-reverse">
                                        <BrandIcon
                                            group="dns-providers"
                                            src={type.icon}
                                        />
                                        <div>
                                            <div className="font-semibold">
                                                {type.label}
                                            </div>
                                            <div className="text-sm text-muted-foreground">
                                                {name || 'Unnamed account'}
                                            </div>
                                        </div>
                                    </div>
                                )
                            }
                        />
                    </>
                )}
            </Form>
        </>
    );
}

CreateDnsProvider.layout = {
    breadcrumbs: [
        { title: 'DNS', href: DnsProviderController.index() },
        { title: 'Add DNS account', href: DnsProviderController.create() },
    ],
};
