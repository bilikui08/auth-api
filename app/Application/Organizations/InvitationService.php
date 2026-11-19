<?php

namespace App\Application\Organizations;

use App\Domain\Auth\Contracts\AuthRepository;
use App\Domain\Auth\DTO\RegisterData;
use App\Domain\Organizations\Exceptions\InvitationNotValid;
use App\Domain\Organizations\Exceptions\OrganizationOperationNotAllowed;
use App\Mail\AddedToOrganizationMail;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cómo se suma una persona a una empresa:
 * - Si ya tiene cuenta: se la agrega al instante y se le avisa por email.
 * - Si no: se crea una invitación y se le manda un enlace con un token secreto (vale 7 días, un solo uso). Al aceptarlo elige
 *   su nombre y contraseña y se crea la cuenta: no hay registro abierto, esta es la única forma de tener una cuenta nueva.
 *
 * El token nunca se devuelve por la API (solo viaja por email): si lo viera quien invita, podría aceptar la invitación
 * en nombre de otra persona y quedarse con su cuenta. Se guarda hasheado.
 */
final class InvitationService
{
    public const VALID_DAYS = 7;

    public function __construct(
        private readonly MemberService $members,
        private readonly AuthRepository $users,
    ) {}

    /**
     * @param list<string> $roles
     * @return array{status: 'added'|'invited', email_sent: bool}
     */
    public function invite(Organization $organization, User $inviter, string $email, array $roles): array
    {
        $email = mb_strtolower(trim($email));
        $existing = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($existing !== null) {
            $this->members->add($organization, $existing, $roles); // falla si ya pertenece

            return ['status' => 'added', 'email_sent' => $this->send($existing->email, new AddedToOrganizationMail($organization, $inviter->name, $this->frontendUrl('/')))];
        }

        $token = Str::random(64);

        DB::transaction(function () use ($organization, $inviter, $email, $roles, $token): void {
            // Reinvitar reemplaza a la anterior: el enlace viejo deja de servir
            Invitation::query()->pending()->where('organization_id', $organization->id)->where('email', $email)->update(['revoked_at' => now()]);

            Invitation::query()->create([
                'organization_id' => $organization->id,
                'email' => $email,
                'roles' => array_values($roles),
                'token_hash' => Invitation::hash($token),
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDays(self::VALID_DAYS),
            ]);
        });

        return [
            'status' => 'invited',
            'email_sent' => $this->send($email, new InvitationMail($organization, $inviter->name, $this->frontendUrl('/accept-invitation?token=' . $token), self::VALID_DAYS)),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function pending(Organization $organization): array
    {
        return Invitation::query()->pending()->with('inviter:id,name')->where('organization_id', $organization->id)->orderByDesc('id')->get()
            ->map(fn (Invitation $i): array => [
                'id' => $i->id,
                'email' => $i->email,
                'roles' => $i->roles,
                'invited_by' => $i->inviter?->name,
                'expires_at' => $i->expires_at->toIso8601String(),
                'created_at' => $i->created_at->toIso8601String(),
            ])->all();
    }

    public function revoke(Organization $organization, int $invitationId): void
    {
        $invitation = Invitation::query()->pending()->where('organization_id', $organization->id)->findOrFail($invitationId);

        $invitation->update(['revoked_at' => now()]);
    }

    /**
     * Qué muestra la pantalla de aceptación: a quién se invita, a qué empresa y con qué roles.
     *
     * @return array{email: string, organization: string, invited_by: string|null, roles: list<array{name: string, description: string|null}>, expires_at: string}
     */
    public function describe(string $token): array
    {
        $invitation = $this->findPending($token);
        $descriptions = Role::query()->whereIn('name', $invitation->roles)->pluck('description', 'name');

        return [
            'email' => $invitation->email,
            'organization' => $invitation->organization->name,
            'invited_by' => $invitation->inviter?->name,
            'roles' => collect($invitation->roles)->map(fn (string $name): array => ['name' => $name, 'description' => $descriptions[$name] ?? null])->all(),
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ];
    }

    /**
     * Acepta la invitación: crea la cuenta (el email queda verificado, porque el enlace llegó a esa casilla) y la suma a la
     * empresa con los roles indicados. Si mientras tanto el email ya tiene cuenta, solo se la suma.
     *
     * @return array{user: User, organization: Organization, created: bool}
     */
    public function accept(string $token, string $name, string $password): array
    {
        return DB::transaction(function () use ($token, $name, $password): array {
            $invitation = $this->findPending($token, lock: true);
            $organization = $invitation->organization;

            $user = User::query()->whereRaw('LOWER(email) = ?', [$invitation->email])->first();
            $created = $user === null;

            if ($created) {
                $user = $this->users->create(new RegisterData($name, $invitation->email, $password));
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            try {
                $this->members->add($organization, $user, $invitation->roles);
            } catch (OrganizationOperationNotAllowed) {
                // ya pertenecía a la empresa: la invitación se da por cumplida
            }

            $invitation->update(['accepted_at' => now()]);

            return ['user' => $user, 'organization' => $organization, 'created' => $created];
        });
    }

    private function findPending(string $token, bool $lock = false): Invitation
    {
        $query = Invitation::query()->pending()->with(['organization', 'inviter:id,name'])->where('token_hash', Invitation::hash($token));
        $invitation = ($lock ? $query->lockForUpdate() : $query)->first();

        if ($invitation === null || ! $invitation->organization->is_active) {
            throw new InvitationNotValid;
        }

        return $invitation;
    }

    private function frontendUrl(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . $path;
    }

    /** El email se manda en el momento (no hay procesos en segundo plano en el hosting). Si falla, se informa pero no se pierde la invitación. */
    private function send(string $to, \Illuminate\Mail\Mailable $mail): bool
    {
        try {
            Mail::to($to)->send($mail);

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
