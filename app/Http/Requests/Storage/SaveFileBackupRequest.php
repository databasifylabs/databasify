<?php

declare(strict_types=1);

namespace App\Http\Requests\Storage;

use App\Models\FileBackupDestination;
use App\Support\Projects\ProjectContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SaveFileBackupRequest extends FormRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $editing = $this->route('destination') instanceof FileBackupDestination;

        return [
            'name' => ['required', 'string', 'max:60'],
            'database_id' => ['required', 'uuid', Rule::exists('databases', 'id')->where('project_id', ProjectContext::current()->id())->whereIn('engine', ['postgres', 'mysql', 'mariadb'])],
            'protocol' => ['required', Rule::in(['ftp', 'ftps', 'sftp'])],
            'host' => ['required', 'string', 'max:253', 'regex:/^[A-Za-z0-9][A-Za-z0-9.:-]*$/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'username' => ['required', 'string', 'max:255', 'not_regex:/[\r\n\x00]/'],
            'path' => ['required', 'string', 'max:200', 'regex:#^/?[A-Za-z0-9_./ -]*$#', 'not_regex:#(^|/)\.\.(/|$)#'],
            'host_key' => ['nullable', 'required_if:protocol,sftp', 'string', 'max:2048', 'regex:#^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(?:256|384|521)) [A-Za-z0-9+/]+={0,3}$#'],
            'password' => [$editing ? 'nullable' : 'required', 'string', 'max:255', 'not_regex:/[\r\n\x00]/'],
            'encryption_password' => [$editing ? 'nullable' : 'required', 'string', 'min:16', 'max:255', 'not_regex:/[\r\n\x00]/'],
            'backup_hour' => ['required', 'integer', 'between:0,23'],
            'enabled' => ['required', 'boolean'],
            'confirm_plain_ftp' => ['exclude_unless:protocol,ftp', 'accepted'],
        ];
    }

    public function toDestination(?FileBackupDestination $existing = null): FileBackupDestination
    {
        $candidate = new FileBackupDestination;
        if ($existing !== null) {
            $candidate->setRawAttributes($existing->getAttributes(), sync: true);
            $candidate->exists = true;
            $credentials = $existing->credentials;
        } else {
            $candidate->id = (string) Str::uuid();
            $credentials = [];
        }
        $candidate->fill([
            'name' => $this->string('name')->trim()->value(),
            'database_id' => $this->string('database_id')->value(),
            'protocol' => $this->string('protocol')->value(),
            'host' => $this->string('host')->value(),
            'port' => $this->integer('port'),
            'username' => $this->string('username')->value(),
            'path' => $this->string('path')->value(),
            'host_key' => $this->string('protocol')->value() === 'sftp' ? $this->string('host_key')->value() : null,
            'backup_hour' => $this->integer('backup_hour'),
            'enabled' => $this->boolean('enabled'),
        ]);
        $candidate->project_id = ProjectContext::current()->id() ?? '';
        foreach (['password', 'encryption_password'] as $key) {
            if ($this->filled($key)) {
                $credentials[$key] = $this->string($key)->value();
            }
        }
        $candidate->credentials = $credentials;

        return $candidate;
    }
}
