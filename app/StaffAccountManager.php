<?php

namespace App;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StaffAccountManager
{
    /** @param array{name: string, email: string, whatsapp_number: string, password: string} $data */
    public function createAdmin(User $actor, array $data): User
    {
        return DB::transaction(function () use ($actor, $data): User {
            $currentActor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $this->assertOwner($currentActor);
            $admin = new User(Arr::only($data, ['name', 'email', 'whatsapp_number']));
            $admin->password = Hash::make($data['password']);
            $admin->forceFill(['role' => UserRole::Admin, 'is_active' => true, 'email_verified_at' => null])->save();
            $this->audit($currentActor, $admin, 'account.admin_created', null, ['role' => 'admin', 'is_active' => true], 'Owner created an Admin account.');

            return $admin;
        });
    }

    public function setActive(User $actor, User $target, bool $active, string $reason): User
    {
        return DB::transaction(function () use ($actor, $target, $active, $reason): User {
            $owners = User::query()->where('role', UserRole::Owner)->orderBy('id')->lockForUpdate()->get();
            $users = User::query()->whereKey([$actor->id, $target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $currentActor = $users->get($actor->id);
            $this->assertOwner($currentActor);
            $currentTarget = $users->get($target->id);
            abort_unless($currentTarget && in_array($currentTarget->role, [UserRole::Admin, UserRole::Owner], true), 404);
            if ($currentTarget->is_active === $active) {
                return $currentTarget;
            }
            if (! $active && $currentTarget->role === UserRole::Owner && $owners->where('is_active', true)->count() <= 1) {
                throw ValidationException::withMessages(['is_active' => 'Owner aktif terakhir tidak dapat dinonaktifkan.']);
            }
            $before = ['is_active' => $currentTarget->is_active];
            $currentTarget->forceFill(['is_active' => $active])->save();
            $this->audit($currentActor, $currentTarget, 'account.activation_changed', $before, ['is_active' => $active], $reason);

            return $currentTarget;
        });
    }

    private function assertOwner(?User $actor): void
    {
        if (! $actor || ! $actor->is_active || $actor->role !== UserRole::Owner) {
            throw new AuthorizationException('Hanya Owner aktif yang dapat mengelola akun staf.');
        }
    }

    /** @param array<string, bool|string>|null $before @param array<string, bool|string> $after */
    private function audit(User $actor, User $target, string $action, ?array $before, array $after, string $reason): void
    {
        $audit = new AuditLog;
        $audit->forceFill([
            'actor_user_id' => $actor->id, 'actor_type' => 'user', 'entity_type' => 'user', 'entity_id' => $target->id,
            'action' => $action, 'before_redacted' => $before, 'after_redacted' => $after, 'reason' => $reason,
            'correlation_id' => (string) Str::uuid(),
        ])->save();
    }
}
