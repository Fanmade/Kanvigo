<?php

namespace App\Http\Resources;

use App\Models\Variable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A project variable: a named stand-in for a fact, written in content as
 * "[name]". A null value means the fact is not decided yet — a normal state, not
 * a missing one.
 *
 * @mixin Variable
 */
class VariableResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'value' => $this->value,
            'description' => $this->description,
        ];
    }
}
