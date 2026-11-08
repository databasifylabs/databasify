<?php

declare(strict_types=1);

namespace App\Http\Requests\Databases;

use App\DTOs\Databases\NewDatabaseNode;
use App\Enums\Databases\NodeKind;
use App\Http\Requests\Concerns\DatabaseValidationRules;
use App\Http\Requests\Concerns\ReadsTypedInput;
use App\Http\Requests\Concerns\ResolvesRouteModel;
use App\Models\Server;
use App\Rules\AvailableServer;
use App\Rules\SharesPrivateNetwork;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddDatabaseNodeRequest extends FormRequest
{
    use DatabaseValidationRules, ReadsTypedInput, ResolvesRouteModel;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'server_id' => ['required', 'uuid', new AvailableServer, new SharesPrivateNetwork($this->routeDatabase()->seed()->server)],
            'kind' => ['sometimes', Rule::enum(NodeKind::class)],
            'name' => array_merge(['required_if:kind,'.NodeKind::ReadOnly->value, 'nullable', 'prohibited_unless:kind,'.NodeKind::ReadOnly->value], $this->nodeNameRules()),
        ];
    }

    public function toNode(): NewDatabaseNode
    {
        return new NewDatabaseNode(
            Server::query()->findOrFail($this->string('server_id')->value()),
            $this->chosenKind(),
            $this->stringOrNull('name'),
        );
    }

    private function chosenKind(): NodeKind
    {
        return $this->enum('kind', NodeKind::class) ?? NodeKind::Member;
    }
}
