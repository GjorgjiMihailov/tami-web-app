<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasRoles;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'disabled_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function assignedCompanies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class);
    }

    public function visibleCompanies(): Builder
    {
        if ($this->hasRole('admin')) {
            return Company::query();
        }

        if ($this->hasRole('client')) {
            return Company::where('id', $this->company_id);
        }

        if ($this->hasRole('accountant')) {
            return Company::whereHas('accountants', fn ($query) => $query->whereKey($this->id));
        }

        return Company::query()->whereNull('id');
    }

    public function latestInvitation(): HasOne
    {
        return $this->hasOne(UserInvitation::class)->latestOfMany();
    }

    /**
     * Состојбата што ја гледа канцеларијата во списокот корисници.
     */
    public function accessStatus(): string
    {
        if ($this->disabled_at !== null) {
            return 'disabled';
        }

        $invitation = $this->latestInvitation;

        if ($invitation === null || $invitation->accepted_at !== null) {
            return 'active';
        }

        return $invitation->expires_at->isFuture() ? 'invited' : 'invitation_expired';
    }
}
