<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * An administrator or moderator. ScamSense has no public accounts —
 * see routes/auth.php.
 */
#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    /**
     * Feedback items this user has moderated.
     *
     * @return HasMany<Feedback, $this>
     */
    public function moderatedFeedback(): HasMany
    {
        return $this->hasMany(Feedback::class, 'moderated_by');
    }

    public function isAdmin(): bool
    {
        return $this->role->canAdminister();
    }

    public function canModerate(): bool
    {
        return $this->role->canModerate();
    }
}
