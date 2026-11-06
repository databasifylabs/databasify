import { Plus } from 'lucide-react';
import StorageProviderController from '@/actions/App/Http/Controllers/Storage/StorageProviderController';
import StorageProviderForm from '@/components/storage/storage-provider-form';

export default function CreateStorageProvider() {
    return (
        <StorageProviderForm
            title="Add storage"
            form={StorageProviderController.store.form()}
            action="Test and add"
            icon={Plus}
        />
    );
}

CreateStorageProvider.layout = {
    breadcrumbs: [
        { title: 'Storage', href: StorageProviderController.index() },
        { title: 'Add storage', href: StorageProviderController.create() },
    ],
};
