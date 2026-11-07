import { Save } from 'lucide-react';
import StorageProviderController from '@/actions/App/Http/Controllers/Storage/StorageProviderController';
import StorageProviderForm from '@/components/storage/storage-provider-form';
import type { StorageProvider } from '@/types';

type Props = {
    provider: StorageProvider;
};

export default function EditStorageProvider({ provider }: Props) {
    return (
        <StorageProviderForm
            title={`Edit ${provider.name}`}
            form={StorageProviderController.update.form({
                provider: provider.id,
            })}
            provider={provider}
            action="Test and save"
            icon={Save}
        />
    );
}

EditStorageProvider.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Storage', href: StorageProviderController.index() },
        {
            title: props.provider.name,
            href: StorageProviderController.edit({
                provider: props.provider.id,
            }),
        },
    ],
});
