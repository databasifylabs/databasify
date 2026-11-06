import { usePage } from '@inertiajs/react';

export function useClusterMemory(): (memoryMb: number | null) => boolean {
    const { clusterMemoryMb } = usePage().props;

    return (memoryMb) => memoryMb === null || memoryMb >= clusterMemoryMb;
}
