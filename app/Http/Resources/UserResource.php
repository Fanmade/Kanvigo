<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The dedicated user representation. The opaque public id is the stable handle
 * other resources reference; the name is always visible, while the email (and any
 * future PII) is only included when the requesting user is entitled to see it —
 * {@see UserPolicy::viewContactInfo()}.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->when(
                $request->user()?->can('viewContactInfo', $this->resource) ?? false,
                fn (): string => $this->email,
            ),
        ];
    }
}
