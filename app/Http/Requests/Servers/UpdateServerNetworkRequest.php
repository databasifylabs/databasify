<?php

declare(strict_types=1);

namespace App\Http\Requests\Servers;

use App\Http\Requests\Concerns\PrivateNetworkRules;
use App\Http\Requests\Concerns\ReadsTypedInput;
use App\Http\Requests\Concerns\ResolvesRouteModel;
use App\Models\Server;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateServerNetworkRequest extends FormRequest
{
    use PrivateNetworkRules, ReadsTypedInput, ResolvesRouteModel;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->privateNetworkRules($this->routeModel('server', Server::class));
    }
}
