import { FileArchive, Upload, X } from 'lucide-react';
import { useRef, useState } from 'react';
import type { DragEvent } from 'react';
import { cn } from '@/lib/utils';

function sizeOf(bytes: number): string {
    return bytes < 1024 * 1024
        ? `${Math.max(1, Math.round(bytes / 1024))} KB`
        : `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

export default function ZipDropzone({
    id,
    name,
    hint,
}: {
    id: string;
    name: string;
    hint: string;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [file, setFile] = useState<File | null>(null);
    const [dragging, setDragging] = useState(false);

    const drop = (event: DragEvent<HTMLLabelElement>) => {
        event.preventDefault();
        setDragging(false);
        const dropped = event.dataTransfer.files;

        if (input.current && dropped.length > 0) {
            input.current.files = dropped;
            setFile(dropped[0]);
        }
    };

    const clear = () => {
        if (input.current) {
            input.current.value = '';
        }

        setFile(null);
    };

    return (
        <div className="space-y-2">
            <input
                ref={input}
                id={id}
                name={name}
                type="file"
                accept=".zip,application/zip"
                className="sr-only"
                onChange={(event) => setFile(event.target.files?.[0] ?? null)}
            />
            {file ? (
                <div className="flex items-center gap-3 rounded-xl border bg-muted/30 p-3">
                    <div className="flex size-9 shrink-0 items-center justify-center rounded-lg border bg-background">
                        <FileArchive className="size-4 text-muted-foreground" />
                    </div>
                    <div className="min-w-0 flex-1">
                        <div className="truncate text-sm font-medium">
                            {file.name}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            {sizeOf(file.size)}
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={clear}
                        aria-label="Remove the zip"
                        className="rounded-md p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                    >
                        <X className="size-4" />
                    </button>
                </div>
            ) : (
                <label
                    htmlFor={id}
                    onDragOver={(event) => {
                        event.preventDefault();
                        setDragging(true);
                    }}
                    onDragLeave={() => setDragging(false)}
                    onDrop={drop}
                    className={cn(
                        'flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-6 text-center transition-colors hover:bg-muted/40',
                        dragging && 'border-primary bg-muted/40',
                    )}
                >
                    <div className="flex size-10 items-center justify-center rounded-full bg-muted">
                        <Upload className="size-5 text-muted-foreground" />
                    </div>
                    <div className="text-sm font-medium">
                        Drop the plugin zip here, or{' '}
                        <span className="underline underline-offset-4">
                            browse
                        </span>
                    </div>
                    <div className="text-xs text-muted-foreground">{hint}</div>
                </label>
            )}
        </div>
    );
}
