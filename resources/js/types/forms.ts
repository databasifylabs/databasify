export type FormField = {
    name: string;
    type: 'text' | 'password' | 'textarea' | 'select';
    label: string;
    description: string | null;
    placeholder: string | null;
    default: string | null;
    options: { value: string; label: string }[];
    optional: boolean;
};
