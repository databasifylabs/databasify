<?php

declare(strict_types=1);

namespace App\Http\Requests\Servers;

use App\DTOs\Servers\NewServer;
use App\Http\Requests\Concerns\PrivateNetworkRules;
use App\Http\Requests\Concerns\ReadsTypedInput;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreServerRequest extends FormRequest
{
    use PrivateNetworkRules, ReadsTypedInput;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge([
            'name' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9][a-z0-9-]*$/', 'unique:servers,name'],
            'public_ip' => ['required', 'ipv4', 'unique:servers,public_ip'],
            'ssh_port' => ['required', 'integer', 'between:1,65535'],
            'ssh_user' => ['required', 'string', 'max:32', 'regex:/^[a-z_][a-z0-9_-]*$/'],
            'ssh_password' => ['nullable', 'string', 'max:255'],
            'disable_password_login' => ['boolean'],
        ], $this->privateNetworkRules());
    }

    public function toServer(): NewServer
    {
        return new NewServer(
            name: $this->string('name')->value(),
            publicIp: $this->string('public_ip')->value(),
            sshPort: $this->integer('ssh_port'),
            sshUser: $this->string('ssh_user')->value(),
            sshPassword: $this->stringOrNull('ssh_password'),
            disablePasswordLogin: $this->boolean('disable_password_login'),
            privateNetwork: $this->toPrivateNetwork(),
        );
    }
}
