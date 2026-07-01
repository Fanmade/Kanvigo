<?php

namespace App\Livewire\Settings;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Support\Avatar;
use Flux\Flux;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Title('Profile settings')]
class Profile extends Component
{
    use ProfileValidationRules;
    use WithFileUploads;

    /**
     * The largest avatar upload accepted, in kilobytes.
     */
    private const int MAX_AVATAR_KILOBYTES = 4096;

    public string $name = '';

    public string $email = '';

    public ?TemporaryUploadedFile $avatar = null;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate($this->profileRules($user->id));

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        Flux::toast(text: __('Profile updated.'), variant: 'success');
    }

    /**
     * Validate, crop to a square and store a freshly uploaded avatar as soon as
     * it finishes uploading, replacing any previous one.
     */
    public function updatedAvatar(): void
    {
        $this->validate([
            'avatar' => ['image', 'mimes:jpeg,png,webp,gif', 'max:'.self::MAX_AVATAR_KILOBYTES],
        ]);

        $image = Avatar::fromImage((string) $this->avatar->get());

        if ($image === null) {
            $this->addError('avatar', __('That image could not be processed.'));

            return;
        }

        $user = Auth::user();

        $user->deleteAvatar();

        $path = 'avatars/'.Str::uuid()->toString().'.png';
        Storage::disk(User::AVATAR_DISK)->put($path, $image);

        $user->forceFill(['avatar_path' => $path])->save();

        $this->reset('avatar');

        Flux::toast(text: __('Avatar updated.'), variant: 'success');
    }

    /**
     * Remove the current user's avatar, falling back to their initials.
     */
    public function removeAvatar(): void
    {
        $user = Auth::user();

        if (! $user->hasAvatar()) {
            return;
        }

        $user->deleteAvatar();
        $user->save();

        $this->reset('avatar');

        Flux::toast(text: __('Avatar removed.'), variant: 'success');
    }

    /**
     * The public URL of the current user's stored avatar, if any.
     */
    #[Computed]
    public function avatarUrl(): ?string
    {
        return Auth::user()->avatarUrl();
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Flux::toast(text: __('A new verification link has been sent to your email address.'));
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        $user = Auth::user();

        return $user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        $user = Auth::user();

        return ! $user instanceof MustVerifyEmail || $user->hasVerifiedEmail();
    }
}
