import { Form } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    DatabaseIcon,
    KeyRound,
    Plus,
    RotateCcw,
    UserRound,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import SchemaController from '@/actions/App/Http/Controllers/Databases/SchemaController';
import DatabaseUserController from '@/actions/App/Http/Controllers/Databases/DatabaseUserController';
import ConfirmDialog from '@/components/confirm-dialog';
import DeleteButton from '@/components/delete-button';
import { runAction } from '@/lib/run-action';
import InputError from '@/components/input-error';
import SecretValue from '@/components/secret-value';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { Database, DatabaseUser, PasswordEncryptionOption } from '@/types';

function EncryptionSelect({
    options,
    value,
    onValueChange,
    name,
}: {
    options: PasswordEncryptionOption[];
    value: string;
    onValueChange: (value: string) => void;
    name?: string;
}) {
    return (
        <Select name={name} value={value} onValueChange={onValueChange}>
            <SelectTrigger
                className="h-9 w-48"
                aria-label="Password encryption"
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

function NameForm({
    form,
    placeholder,
    action,
    success,
    children,
}: {
    form: { action: string; method: 'post' };
    placeholder: string;
    action: string;
    success: (name: string) => string;
    children?: ReactNode;
}) {
    const [name, setName] = useState('');

    return (
        <Form
            {...form}
            options={{ preserveScroll: true }}
            resetOnSuccess
            onSuccess={() => {
                toast.success(success(name));
                setName('');
            }}
            className="flex flex-wrap items-start gap-2 border-t px-6 pt-4"
        >
            {({ processing, errors }) => (
                <>
                    <div className="min-w-48 flex-1 space-y-1">
                        <Input
                            name="name"
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                            placeholder={placeholder}
                            autoComplete="off"
                            className="h-9"
                        />
                        <InputError message={errors.name ?? errors.database} />
                    </div>
                    {children}
                    <Button
                        size="sm"
                        className="h-9"
                        disabled={processing || !name}
                    >
                        <Plus />
                        {action}
                    </Button>
                </>
            )}
        </Form>
    );
}

function SectionHeader({
    icon: Icon,
    title,
    description,
}: {
    icon: LucideIcon;
    title: string;
    description: string;
}) {
    return (
        <CardHeader>
            <CardTitle className="flex items-center gap-2">
                <Icon className="size-4 text-muted-foreground" />
                {title}
            </CardTitle>
            <CardDescription>{description}</CardDescription>
        </CardHeader>
    );
}

function LockedDeleteButton({
    label,
    reason,
}: {
    label: string;
    reason: string;
}) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span tabIndex={0} className="inline-flex">
                    <DeleteButton aria-label={label} disabled />
                </span>
            </TooltipTrigger>
            <TooltipContent>{reason}</TooltipContent>
        </Tooltip>
    );
}

function UserRow({
    database,
    user,
}: {
    database: Database;
    user: DatabaseUser;
}) {
    const options = database.password_encryptions;

    return (
        <TableRow>
            <TableCell className="pl-6">
                <div className="flex items-center gap-3">
                    <span className="flex size-8 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary uppercase">
                        {user.name.slice(0, 2)}
                    </span>
                    <span className="font-medium">{user.name}</span>
                </div>
            </TableCell>
            <TableCell className="whitespace-normal">
                <SecretValue value={user.password} />
            </TableCell>
            {options.length > 0 && (
                <TableCell>
                    <EncryptionSelect
                        options={options}
                        value={user.password_encryption ?? ''}
                        onValueChange={(value) =>
                            runAction(
                                DatabaseUserController.updateEncryption({
                                    database: database.id,
                                    user: user.id,
                                }),
                                {
                                    data: { password_encryption: value },
                                    success: `Password encryption updated for ${user.name}`,
                                },
                            )
                        }
                    />
                </TableCell>
            )}
            <TableCell className="pr-6 text-right">
                <div className="flex justify-end gap-1">
                    <ConfirmDialog
                        trigger={
                            <Button variant="ghost" size="sm">
                                <RotateCcw />
                                Reset password
                            </Button>
                        }
                        title={`Reset the password for ${user.name}?`}
                        description="Apps using the current password lose access until they are updated with the new one."
                        confirmLabel="Reset password"
                        onConfirm={() =>
                            runAction(
                                DatabaseUserController.resetPassword({
                                    database: database.id,
                                    user: user.id,
                                }),
                                {
                                    success: `New password set for ${user.name}`,
                                },
                            )
                        }
                    />
                    {user.is_default ? (
                        <LockedDeleteButton
                            label={`Delete ${user.name}`}
                            reason="The default user can't be deleted"
                        />
                    ) : (
                        <ConfirmDialog
                            trigger={
                                <DeleteButton
                                    aria-label={`Delete ${user.name}`}
                                />
                            }
                            title={`Delete user ${user.name}?`}
                            description="Connections using this user are refused from now on."
                            confirmLabel="Delete user"
                            destructive
                            onConfirm={() =>
                                runAction(
                                    DatabaseUserController.destroy({
                                        database: database.id,
                                        user: user.id,
                                    }),
                                    { success: `Deleted ${user.name}` },
                                )
                            }
                        />
                    )}
                </div>
            </TableCell>
        </TableRow>
    );
}

function ValkeyAccess({
    database,
    appEnv,
}: {
    database: Database;
    appEnv: Record<string, string>;
}) {
    const password = appEnv.REDIS_PASSWORD ?? '';

    return (
        <Card>
            <SectionHeader
                icon={KeyRound}
                title="Access"
                description={`${database.engine_label} uses one password for the default user. Every connection must use TLS.`}
            />
            <CardContent>
                <div className="flex flex-wrap items-center gap-4 rounded-lg border bg-muted/30 px-4 py-3">
                    <UserRound className="size-5 text-muted-foreground" />
                    <div className="text-sm">
                        <div className="font-medium">default</div>
                        <div className="text-muted-foreground">Full access</div>
                    </div>
                    <SecretValue
                        value={password}
                        className="w-full sm:ml-auto sm:w-auto"
                    />
                </div>
            </CardContent>
        </Card>
    );
}

export default function UsersTab({
    database,
    appEnv,
}: {
    database: Database;
    appEnv: Record<string, string>;
}) {
    const options = database.password_encryptions;
    const [encryption, setEncryption] = useState(options[0]?.value ?? '');

    if (!database.supports_users) {
        return <ValkeyAccess database={database} appEnv={appEnv} />;
    }

    return (
        <div className="space-y-6">
            <Card className="gap-4 pb-4">
                <SectionHeader
                    icon={UserRound}
                    title="Users"
                    description="Each user gets a generated password and full access to the databases you create."
                />
                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead className="pl-6">Name</TableHead>
                            <TableHead>Password</TableHead>
                            {options.length > 0 && (
                                <TableHead>Password encryption</TableHead>
                            )}
                            <TableHead className="pr-6" />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {database.users.map((user) => (
                            <UserRow
                                key={user.id}
                                database={database}
                                user={user}
                            />
                        ))}
                    </TableBody>
                </Table>
                {options.length > 0 && (
                    <p className="px-6 text-sm text-muted-foreground">
                        Having trouble connecting? Your MySQL client might not
                        support the MySQL 8 default password encryption. If the
                        default (caching_sha2_password) gives you trouble,
                        change the user to Legacy (mysql_native_password).
                    </p>
                )}
                <NameForm
                    form={DatabaseUserController.store.form({
                        database: database.id,
                    })}
                    placeholder="New user name"
                    action="Add user"
                    success={(name) => `Created user ${name}`}
                >
                    {options.length > 0 && (
                        <EncryptionSelect
                            name="password_encryption"
                            options={options}
                            value={encryption}
                            onValueChange={setEncryption}
                        />
                    )}
                </NameForm>
            </Card>

            <Card className="gap-4 pb-4">
                <SectionHeader
                    icon={DatabaseIcon}
                    title="Databases"
                    description="Databases live on every node and replicate automatically."
                />
                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead className="pl-6">Name</TableHead>
                            <TableHead className="pr-6" />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {database.schemas.map((schema) => (
                            <TableRow key={schema.id}>
                                <TableCell className="pl-6">
                                    <div className="flex items-center gap-3">
                                        <span className="flex size-8 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                            <DatabaseIcon className="size-4" />
                                        </span>
                                        <span className="font-medium">
                                            {schema.name}
                                        </span>
                                    </div>
                                </TableCell>
                                <TableCell className="pr-6 text-right">
                                    {schema.is_default ? (
                                        <LockedDeleteButton
                                            label={`Delete ${schema.name}`}
                                            reason="The default database can't be deleted"
                                        />
                                    ) : (
                                        <ConfirmDialog
                                            trigger={
                                                <DeleteButton
                                                    aria-label={`Delete ${schema.name}`}
                                                />
                                            }
                                            title={`Delete database ${schema.name}?`}
                                            description="Every table and row in it is dropped on all nodes. This cannot be undone."
                                            confirmLabel="Delete database"
                                            destructive
                                            typeToConfirm={schema.name}
                                            onConfirm={() =>
                                                runAction(
                                                    SchemaController.destroy({
                                                        database: database.id,
                                                        schema: schema.id,
                                                    }),
                                                    {
                                                        success: `Deleted ${schema.name}`,
                                                    },
                                                )
                                            }
                                        />
                                    )}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
                <NameForm
                    form={SchemaController.store.form({
                        database: database.id,
                    })}
                    placeholder="New database name"
                    action="Add database"
                    success={(name) => `Created database ${name}`}
                />
            </Card>
        </div>
    );
}
