import { Form, Head } from '@inertiajs/react';
import {
    Cloud,
    KeyRound,
    Lock,
    Plus,
    Server as ServerIcon,
} from 'lucide-react';
import { useState } from 'react';
import ServerController from '@/actions/App/Http/Controllers/Servers/ServerController';
import CopyButton from '@/components/copy-button';
import {
    CheckMark,
    ChoiceRow,
    choiceRowClass,
    FormSection,
} from '@/components/form/choice';
import SubmitPanel from '@/components/form/submit-panel';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PrivateNetworkFields from '@/components/servers/private-network-fields';
import { isDatabasifyNetwork } from '@/components/servers/network-mark';
import ProviderServerForm from '@/components/servers/provider-server-form';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type {
    NetworkSummary,
    OperatingSystemOption,
    ProviderOption,
} from '@/types';

type Source = 'provider' | 'existing';
type Access = 'password' | 'key';

function Option({
    selected,
    icon: Icon,
    title,
    description,
    onSelect,
}: {
    selected: boolean;
    icon: typeof Cloud;
    title: string;
    description: string;
    onSelect: () => void;
}) {
    return (
        <ChoiceRow selected={selected} onSelect={onSelect}>
            <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                <Icon className="size-4" />
            </span>
            <span className="min-w-0 flex-1">
                <span className="block font-medium">{title}</span>
                <span className="block text-sm text-muted-foreground">
                    {description}
                </span>
            </span>
        </ChoiceRow>
    );
}

function ExistingServerForm({
    publicKey,
    networks,
}: {
    publicKey: string;
    networks: NetworkSummary[];
}) {
    const [access, setAccess] = useState<Access>('password');
    const [hardened, setHardened] = useState(true);
    const [name, setName] = useState('');
    const [address, setAddress] = useState('');
    const installCommand = `mkdir -p ~/.ssh && echo '${publicKey}' >> ~/.ssh/authorized_keys`;
    const missing =
        address === ''
            ? 'Enter the public IP'
            : name === ''
              ? 'Name the server'
              : null;

    return (
        <Form {...ServerController.store.form()} className="space-y-5">
            {({ processing, errors }) => (
                <>
                    <FormSection title="Connection">
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-[1fr_7rem_1fr]">
                            <div className="space-y-1.5">
                                <Label htmlFor="public_ip">Public IP</Label>
                                <Input
                                    id="public_ip"
                                    name="public_ip"
                                    required
                                    value={address}
                                    onChange={(event) =>
                                        setAddress(event.target.value.trim())
                                    }
                                    placeholder="203.0.113.10"
                                    className="font-mono"
                                />
                                <InputError message={errors.public_ip} />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="ssh_port">SSH port</Label>
                                <Input
                                    id="ssh_port"
                                    name="ssh_port"
                                    type="number"
                                    defaultValue={22}
                                    required
                                />
                                <InputError message={errors.ssh_port} />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="ssh_user">SSH user</Label>
                                <Input
                                    id="ssh_user"
                                    name="ssh_user"
                                    defaultValue="root"
                                    required
                                />
                                <InputError message={errors.ssh_user} />
                            </div>
                        </div>
                    </FormSection>

                    <FormSection
                        title="First sign-in"
                        description="After this, Databasify always uses its own key."
                    >
                        <div
                            className="grid grid-cols-1 gap-2 md:grid-cols-2"
                            role="radiogroup"
                        >
                            <Option
                                selected={access === 'password'}
                                icon={Lock}
                                title="Password"
                                description="Used once to install the key, then forgotten."
                                onSelect={() => setAccess('password')}
                            />
                            <Option
                                selected={access === 'key'}
                                icon={KeyRound}
                                title="SSH key"
                                description="You add the Databasify key yourself."
                                onSelect={() => setAccess('key')}
                            />
                        </div>

                        {access === 'password' ? (
                            <div className="space-y-1.5 md:max-w-sm">
                                <Label htmlFor="ssh_password">Password</Label>
                                <Input
                                    id="ssh_password"
                                    name="ssh_password"
                                    type="password"
                                    autoComplete="off"
                                />
                                <InputError message={errors.ssh_password} />
                            </div>
                        ) : (
                            <div className="overflow-hidden rounded-xl border bg-neutral-950">
                                <div className="flex items-center justify-between border-b border-white/10 px-4 py-2 text-xs text-neutral-400">
                                    Run this on the server as the SSH user
                                    <CopyButton
                                        value={installCommand}
                                        label="Copy command"
                                    />
                                </div>
                                <pre className="overflow-x-auto p-4 font-mono text-xs leading-relaxed break-all whitespace-pre-wrap text-neutral-100">
                                    {installCommand}
                                </pre>
                            </div>
                        )}

                        <input
                            type="hidden"
                            name="disable_password_login"
                            value={hardened ? '1' : '0'}
                        />
                        <div
                            role="checkbox"
                            aria-checked={hardened}
                            tabIndex={0}
                            onClick={() => setHardened(!hardened)}
                            onKeyDown={(event) => {
                                if (
                                    event.key === ' ' ||
                                    event.key === 'Enter'
                                ) {
                                    event.preventDefault();
                                    setHardened(!hardened);
                                }
                            }}
                            className={choiceRowClass(false)}
                        >
                            <CheckMark selected={hardened} />
                            <span className="min-w-0 flex-1">
                                <span className="block font-medium">
                                    Turn off SSH password login
                                </span>
                                <span className="block text-sm text-muted-foreground">
                                    Recommended. After setup, only SSH keys can
                                    sign in.
                                </span>
                            </span>
                        </div>
                    </FormSection>

                    <FormSection
                        title="Private network"
                        description="Standby and read-only nodes must share the primary's network."
                    >
                        <PrivateNetworkFields
                            networks={networks}
                            errors={errors}
                        />
                    </FormSection>

                    <SubmitPanel
                        name={name}
                        onName={setName}
                        nameLabel="Server name"
                        placeholder="server-01"
                        error={errors.name}
                        processing={processing}
                        missing={missing}
                        action="Add server"
                        icon={Plus}
                        summary={
                            <>
                                <div className="font-mono font-semibold">
                                    {address || 'No address yet'}
                                </div>
                                <div className="text-sm text-muted-foreground">
                                    Setup installs Docker and takes 2 to 4
                                    minutes.
                                </div>
                            </>
                        }
                    />
                </>
            )}
        </Form>
    );
}

export default function CreateServer({
    publicKey,
    networks,
    providers,
    operatingSystems,
}: {
    publicKey: string;
    networks: NetworkSummary[];
    providers: ProviderOption[];
    operatingSystems: OperatingSystemOption[];
}) {
    const [source, setSource] = useState<Source>(
        providers.length > 0 ? 'provider' : 'existing',
    );

    return (
        <>
            <Head title="Add server" />
            <div className="mx-auto w-full max-w-5xl space-y-5 p-4 pb-10 md:p-6">
                <Heading title="Add server" />

                <FormSection title="Where it runs">
                    <div
                        className="grid grid-cols-1 gap-2 md:grid-cols-2"
                        role="radiogroup"
                    >
                        <Option
                            selected={source === 'provider'}
                            icon={Cloud}
                            title="New server at a provider"
                            description="Created in your cloud account's private network."
                            onSelect={() => setSource('provider')}
                        />
                        <Option
                            selected={source === 'existing'}
                            icon={ServerIcon}
                            title="Existing server"
                            description="A supported Linux server, reachable over SSH."
                            onSelect={() => setSource('existing')}
                        />
                    </div>
                </FormSection>

                {source === 'provider' ? (
                    <ProviderServerForm
                        providers={providers}
                        operatingSystems={operatingSystems}
                        databasifyNetworks={networks.filter(
                            isDatabasifyNetwork,
                        )}
                    />
                ) : (
                    <ExistingServerForm
                        publicKey={publicKey}
                        networks={networks}
                    />
                )}
            </div>
        </>
    );
}

CreateServer.layout = () => ({
    breadcrumbs: [
        { title: 'Servers', href: ServerController.index() },
        { title: 'Add server', href: ServerController.create() },
    ],
});
