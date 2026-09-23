<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\PortalApp;
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
    use HasFactory, HasRoles, Notifiable;

    /**
     * Стандардната вредност на колоната важи за базата, не за свежосоздаден
     * модел во меморија — без ова `new User` чита `null` и правото паѓа.
     */
    protected $attributes = [
        'app_prodazba' => true,
        'app_finansii' => true,
        'app_plata' => true,
    ];

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
            'app_prodazba' => 'boolean',
            'app_finansii' => 'boolean',
            'app_plata' => 'boolean',
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

    /** Клиентски профил е секој што ја носи една од двете клиентски улоги. */
    public function isClient(): bool
    {
        return $this->hasAnyRole(['internal_client', 'freelancer_client']);
    }

    public function visibleCompanies(): Builder
    {
        if ($this->hasRole('admin')) {
            return Company::query();
        }

        if ($this->isClient()) {
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

    /**
     * Смее ли овој човек да влезе во оваа апликација. Порталот е отворен за секој
     * најавен корисник, а админот ги прескокнува штиклирањата — не смее да се
     * заклучи сам од себе.
     */
    public function canAccessApp(PortalApp $app): bool
    {
        $column = $app->userColumn();

        if ($column === null || $this->hasRole('admin')) {
            return true;
        }

        return (bool) $this->{$column};
    }
}
