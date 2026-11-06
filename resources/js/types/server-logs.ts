export type ServerLogStatus = 'running' | 'succeeded' | 'failed';

export type ServerLog = {
    id: string;
    name: string;
    status: ServerLogStatus;
    exit_code: number | null;
    server: { id: string; name: string };
    database: { id: string; name: string } | null;
    group: { id: string; name: string | null } | null;
    created_at: string | null;
    updated_at: string | null;
};

export type ServerLogWithOutput = ServerLog & { output: string };
