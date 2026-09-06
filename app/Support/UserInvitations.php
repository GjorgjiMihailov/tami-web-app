<?php

namespace App\Support;

use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Поканата е еднократен линк со кој корисникот си поставува лозинка. Логиката
 * седи тука, а не во Livewire компонентите, зашто екранот кај клиент и екранот
 * на канцеларијата ја делат.
 */
class UserInvitations
{
    public const DAYS_VALID = 7;

    /**
     * Прави нова покана и го враќа целосниот линк. Секоја претходна
     * неискористена покана за истиот корисник престанува да важи.
     */
    public static function issue(User $user, User $issuedBy): string
    {
        $plain = Str::random(64);

        return DB::transaction(function () use ($user, $issuedBy, $plain) {
            UserInvitation::query()
                ->where('user_id', $user->id)
                ->whereNull('accepted_at')
                ->delete();

            UserInvitation::create([
                'user_id' => $user->id,
                'token_hash' => hash('sha256', $plain),
                'expires_at' => now()->addDays(self::DAYS_VALID),
                'created_by' => $issuedBy->id,
            ]);

            return route('invitation.accept', ['token' => $plain]);
        });
    }

    public static function find(string $plainToken): ?UserInvitation
    {
        return UserInvitation::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Ја троши поканата и ја поставува лозинката. Враќа `null` за секоја
     * причина поради која линкот не важи — екранот потоа кажува една иста
     * порака, за да не открива дали адресата постои.
     */
    public static function accept(string $plainToken, string $password): ?User
    {
        $invitation = self::find($plainToken);

        if ($invitation === null) {
            return null;
        }

        $user = $invitation->user;

        if ($user === null || $user->disabled_at !== null) {
            return null;
        }

        DB::transaction(function () use ($invitation, $user, $password) {
            $user->forceFill(['password' => Hash::make($password)])->save();
            $invitation->forceFill(['accepted_at' => now()])->save();
        });

        return $user;
    }
}
