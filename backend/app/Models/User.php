<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\HasApiTokens;
use Tymon\JWTAuth\Contracts\JWTSubject;


class User extends Authenticatable implements JWTSubject
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'ex_users';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'google_id',
        'photo_path',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'notify_whatsapp' => 'boolean',
    ];

    protected $attributes = [
        'role' => 'user',
    ];

    /**
     * URL da foto de perfil, resolvida por precedência: foto enviada pelo
     * próprio usuário (`photo_path`, servida pela rota assinada `user.photo` —
     * ADR-005) > foto vinda do login Google (coluna `avatar_url`) > `null`.
     * Como sobrescreve a leitura da coluna `avatar_url`, `GET /api/me` já
     * devolve o valor resolvido sem o frontend precisar mudar.
     */
    public function getAvatarUrlAttribute(?string $value): ?string
    {
        if ($this->photo_path) {
            return URL::temporarySignedRoute('user.photo', now()->addMinutes(30), ['userId' => $this->id]);
        }

        return $value;
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [
            'role' => $this->role,
        ];
    }

    public function paidExpenses()
    {
        return $this->belongsToMany(Expense::class, 'ex_expenses_payers', 'user_id', 'expense_id');
    }

    public function groups()
    {
        return $this->belongsToMany(Group::class, 'ex_groups_members', 'user_id', 'group_id');
    }

    public function invitedBy()
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function invitedUsers()
    {
        return $this->hasMany(User::class, 'invited_by');
    }

}
