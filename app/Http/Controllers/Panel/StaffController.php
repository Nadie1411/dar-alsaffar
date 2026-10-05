<?php

namespace App\Http\Controllers\Panel;

use App\Enums\AdminRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\StaffRequest;
use App\Models\User;
use App\Services\Store\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Who has access to the panel, and what each of them may open. Owners only.
 */
class StaffController extends Controller
{
    public function __construct(protected ActivityLogger $log) {}

    public function index(): View
    {
        return view('panel.staff.index', [
            'members' => User::query()->orderByDesc('is_active')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('panel.staff.form', [
            'member' => new User(['role' => AdminRole::Staff, 'is_active' => true, 'locale' => 'ar']),
        ]);
    }

    public function store(StaffRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $member = User::query()->create([
            'name' => trim($data['name']),
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => $data['role'],
            'locale' => $data['locale'],
            'is_active' => $request->boolean('is_active'),
        ]);

        $this->log->record($request->user('staff'), 'staff.created', $member, ['role' => $member->role->value]);

        return redirect()->route('panel.staff.index')->with('status', __('panel.staff.created'));
    }

    public function edit(User $member): View
    {
        return view('panel.staff.form', ['member' => $member]);
    }

    public function update(StaffRequest $request, User $member): RedirectResponse
    {
        $data = $request->validated();
        $actor = $request->user('staff');
        $role = AdminRole::from($data['role']);
        $active = $request->boolean('is_active');

        // An owner cannot lock themselves out, and the shop must never be left
        // with nobody who can manage staff.
        if (($role !== AdminRole::Owner || ! $active) && $this->wouldLeaveNoOwner($member)) {
            return back()->withInput()->with('warning', __('panel.staff.lastOwner'));
        }

        if ($member->is($actor) && ! $active) {
            return back()->withInput()->with('warning', __('panel.staff.cannotDeactivateSelf'));
        }

        $member->fill([
            'name' => trim($data['name']),
            'email' => $data['email'],
            'role' => $role,
            'locale' => $data['locale'],
            'is_active' => $active,
        ]);

        $passwordChanged = filled($data['password'] ?? null);

        if ($passwordChanged) {
            $member->password = $data['password'];
        }

        $roleChanged = $member->isDirty('role');
        $member->save();

        $this->log->record($actor, 'staff.updated', $member, array_filter([
            'role' => $roleChanged ? $role->value : null,
            'active' => $active,
            'password_changed' => $passwordChanged ?: null,
        ], fn ($value) => $value !== null));

        return redirect()->route('panel.staff.index')->with('status', __('panel.staff.updated'));
    }

    public function destroy(Request $request, User $member): RedirectResponse
    {
        $actor = $request->user('staff');

        if ($member->is($actor)) {
            return back()->with('warning', __('panel.staff.cannotDeleteSelf'));
        }

        if ($this->wouldLeaveNoOwner($member)) {
            return back()->with('warning', __('panel.staff.lastOwner'));
        }

        $label = $member->name;

        $member->delete();

        $this->log->record($actor, 'staff.deleted', null, [], $label);

        return redirect()->route('panel.staff.index')->with('status', __('panel.staff.deleted'));
    }

    /** Whether taking this member out of the owner role (or off the team) would leave no active owner. */
    protected function wouldLeaveNoOwner(User $member): bool
    {
        if ($member->role !== AdminRole::Owner || ! $member->is_active) {
            return false;
        }

        return ! User::query()
            ->where('role', AdminRole::Owner->value)
            ->where('is_active', true)
            ->whereKeyNot($member->id)
            ->exists();
    }
}
