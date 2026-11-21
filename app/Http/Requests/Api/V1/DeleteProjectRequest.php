<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Support\Projects\ProjectContext;
use Illuminate\Foundation\Http\FormRequest;

class DeleteProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ! ProjectContext::current()->isLimited();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
