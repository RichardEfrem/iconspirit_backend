<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function findById(int $id): User
    {
        return User::findOrFail($id);
    }

    public function list(?int $perPage = null, ?string $search = null, ?string $role = null, ?string $status = null)
    {
        $query = User::query()->orderBy('id', 'desc');

        if ($search) {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like) {
                $q->where('username', 'like', $like)
                  ->orWhere('name', 'like', $like)
                  ->orWhere('email', 'like', $like);
            });
        }

        if ($role) {
            $query->where('role', $role);
        }

        if ($status) {
            $query->where('status', $status);
        }

        return $perPage
            ? $query->paginate($perPage)
            : $query->get();
    }

    /**
     * @throws ValidationException
     */
    public function create(array $data): User
    {
        $validated = Validator::make($data, [
            'username' => ['required', 'string', 'max:50', 'unique:users,username'],
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'role'     => ['required', 'string', Rule::in(User::ROLES)],
            'status'   => ['sometimes', 'string', Rule::in(User::STATUSES)],
        ])->validate();

        $validated['status'] = $validated['status'] ?? User::STATUS_ACTIVE;

        return User::create($validated);
    }

    /**
     * @throws ValidationException
     * @throws ModelNotFoundException
     */
    public function update(int $id, array $data, ?int $actingUserId = null): User
    {
        $user = $this->findById($id);

        $validated = Validator::make($data, [
            'username' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('users', 'username')->ignore($user->id)],
            'name'     => ['sometimes', 'required', 'string', 'max:255'],
            'email'    => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'max:255'],
            'role'     => ['sometimes', 'required', 'string', Rule::in(User::ROLES)],
            'status'   => ['sometimes', 'required', 'string', Rule::in(User::STATUSES)],
        ])->validate();

        if (array_key_exists('password', $validated) && !$validated['password']) {
            unset($validated['password']);
        }

        if ($actingUserId !== null && $actingUserId === $user->id) {
            if (isset($validated['status']) && $validated['status'] !== User::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'status' => ['You cannot deactivate your own account.'],
                ]);
            }
            if (isset($validated['role']) && $validated['role'] !== $user->role) {
                throw ValidationException::withMessages([
                    'role' => ['You cannot change your own role.'],
                ]);
            }
        }

        $user->update($validated);

        return $user->fresh();
    }

    /**
     * @throws ValidationException
     * @throws ModelNotFoundException
     */
    public function setStatus(int $id, string $status, ?int $actingUserId = null): User
    {
        if (!in_array($status, User::STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => ['Invalid status.'],
            ]);
        }

        $user = $this->findById($id);

        if ($actingUserId !== null && $actingUserId === $user->id && $status !== User::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'status' => ['You cannot deactivate your own account.'],
            ]);
        }

        $user->update(['status' => $status]);

        return $user->fresh();
    }

    /**
     * @throws ValidationException
     * @throws ModelNotFoundException
     */
    public function delete(int $id, ?int $actingUserId = null): bool
    {
        $user = $this->findById($id);

        if ($actingUserId !== null && $actingUserId === $user->id) {
            throw ValidationException::withMessages([
                'id' => ['You cannot delete your own account.'],
            ]);
        }

        return (bool) $user->delete();
    }
}
