<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index()
    {
        return view('settings.users.index', [
            'users' => User::with('department')->orderBy('name')->paginate(20),
        ]);
    }

    public function create()
    {
        return view('settings.users.create', [
            'departments' => Department::orderBy('name')->get(),
            'roles' => $this->roles(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Plain username for now, not a real email address (2026-09-30).
            'email' => ['required', 'string', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'in:'.implode(',', array_keys($this->roles()))],
            'department_id' => ['nullable', 'exists:departments,id'],
            'position' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['password'] = Hash::make($data['password']);
        $data['is_active'] = $request->boolean('is_active', true);
        $data['email_verified_at'] = now();

        User::create($data);

        return redirect()->route('settings.users.index')->with('success', 'เพิ่มผู้ใช้งานเรียบร้อยแล้ว');
    }

    public function edit(User $user)
    {
        return view('settings.users.edit', [
            'user' => $user,
            'departments' => Department::orderBy('name')->get(),
            'roles' => $this->roles(),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Plain username for now, not a real email address (2026-09-30).
            'email' => ['required', 'string', 'max:255', 'unique:users,email,'.$user->id],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['required', 'in:'.implode(',', array_keys($this->roles()))],
            'department_id' => ['nullable', 'exists:departments,id'],
            'position' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }
        $data['is_active'] = $request->boolean('is_active');

        $user->update($data);

        return redirect()->route('settings.users.index')->with('success', 'บันทึกข้อมูลผู้ใช้งานเรียบร้อยแล้ว');
    }

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'ไม่สามารถลบบัญชีของตัวเองได้');
        }

        $user->delete();

        return back()->with('success', 'ลบผู้ใช้งานเรียบร้อยแล้ว');
    }

    private function roles(): array
    {
        return [
            User::ROLE_ADMIN => 'Admin',
            User::ROLE_PROJECT_MANAGER => 'Project Manager',
            User::ROLE_SALES => 'Sales',
            User::ROLE_PRODUCTION => 'Production',
            User::ROLE_MEMBER => 'Member',
        ];
    }
}
