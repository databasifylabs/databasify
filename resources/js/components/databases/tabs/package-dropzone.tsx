import { Package, Plus, Upload, X } from 'lucide-react';
import { useRef, useState } from 'react';
import type { DragEvent } from 'react';
import { formatFileSize } from '@/lib/format';
import { cn } from '@/lib/utils';

const MAX_FILES = 10;
const MAX_FILE_BYTES = 200 * 1024 * 1024;
const MAX_UPLOAD_BYTES = 512 * 1024 * 1024;

export function uploadProblemOf(files: File[]): string | null {
    if (files.length > MAX_FILES) {
        return `Pick at most ${MAX_FILES} files.`;
    }

    if (files.some((file) => file.size > MAX_FILE_BYTES)) {
        return 'Each file can be up to 200 MB.';
    }

    if (
        files.reduce((total, file) => total + file.size, 0) > MAX_UPLOAD_BYTES
    ) {
        return 'All files together can be up to 512 MB.';
    }

    return null;
}

export default function PackageDropzone({
    format,
    files,
    onChange,
}: {
    format: string;
    files: File[];
    onChange: (files: File[]) => void;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [dragging, setDragging] = useState(false);
    const [skipped, setSkipped] = useState<string[]>([]);
    const extension = `.${format}`;
    const problem = uploadProblemOf(files);
    const total = files.reduce((sum, file) => sum + file.size, 0);

    const add = (chosen: File[]) => {
        const fitting = chosen.filter((file) =>
            file.name.toLowerCase().endsWith(extension),
        );
        const names = new Set(fitting.map((file) => file.name));

        setSkipped(
            chosen
                .filter((file) => !fitting.includes(file))
                .map((file) => file.name),
        );
        onChange([
            ...files.filter((file) => !names.has(file.name)),
            ...fitting,
        ]);
    };

    const drop = (event: DragEvent<HTMLLabelElement>) => {
        event.preventDefault();
        setDragging(false);
        add(Array.from(event.dataTransfer.files));
    };

    return (
        <div className="space-y-2">
            <input
                ref={input}
                id="package-files"
                type="file"
                accept={extension}
                multiple
                className="sr-only"
                onChange={(event) => {
                    add(Array.from(event.target.files ?? []));
                    event.target.value = '';
                }}
            />
            {files.length > 0 && (
                <ul className="divide-y rounded-xl border">
                    {files.map((file) => (
                        <li
                            key={file.name}
                            className="flex items-center gap-3 p-2.5"
                        >
                            <div className="flex size-8 shrink-0 items-center justify-center rounded-lg border bg-muted/30">
                                <Package className="size-4 text-muted-foreground" />
                            </div>
                            <div className="min-w-0 flex-1">
                                <div className="truncate text-sm font-medium">
                                    {file.name}
                                </div>
                                <div
                                    className={cn(
                                        'text-xs',
                                        file.size > MAX_FILE_BYTES
                                            ? 'text-destructive'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {formatFileSize(file.size)}
                                    {file.size > MAX_FILE_BYTES &&
                                        ' · over 200 MB'}
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() =>
                                    onChange(
                                        files.filter(
                                            (kept) => kept.name !== file.name,
                                        ),
                                    )
                                }
                                aria-label={`Remove ${file.name}`}
                                className="rounded-md p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                            >
                                <X className="size-4" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            <label
                htmlFor="package-files"
                onDragOver={(event) => {
                    event.preventDefault();
                    setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={drop}
                className={cn(
                    'flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border border-dashed text-center transition-colors hover:bg-muted/40',
                    files.length > 0 ? 'p-3' : 'p-6',
                    dragging && 'border-primary bg-muted/40',
                )}
            >
                {files.length > 0 ? (
                    <div className="flex items-center gap-2 text-sm font-medium">
                        <Plus className="size-4 text-muted-foreground" />
                        Add more {extension} files
                    </div>
                ) : (
                    <>
                        <div className="flex size-10 items-center justify-center rounded-full bg-muted">
                            <Upload className="size-5 text-muted-foreground" />
                        </div>
                        <div className="text-sm font-medium">
                            Drop {extension} files here, or{' '}
                            <span className="underline underline-offset-4">
                                browse
                            </span>
                        </div>
                        <div className="text-xs text-muted-foreground">
                            Built for the system above, one file per package. Up
                            to {MAX_FILES} files, 200 MB each and 512 MB in
                            total.
                        </div>
                    </>
                )}
            </label>
            {files.length > 0 && (
                <p
                    className={cn(
                        'text-xs',
                        problem === null
                            ? 'text-muted-foreground'
                            : 'text-destructive',
                    )}
                >
                    {problem ??
                        `${files.length} ${files.length === 1 ? 'file' : 'files'}, ${formatFileSize(total)} in total.`}
                </p>
            )}
            {skipped.length > 0 && (
                <p className="text-xs text-destructive">
                    Skipped {skipped.join(', ')}: only {extension} files can be
                    added.
                </p>
            )}
        </div>
    );
}
