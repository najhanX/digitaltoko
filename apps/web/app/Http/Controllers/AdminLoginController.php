<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class AdminLoginController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/AdminLogin');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string|max:128']);
        $admin = User::where('email', strtolower($data['email']))->where('role', 'admin')->first();
        if (! $admin || ! Hash::check($data['password'], $admin->password)) {
            return back()->withErrors(['email' => 'Email atau password admin tidak sesuai.']);
        }
        Auth::guard('staff')->logout();
        Auth::guard('web')->login($admin);
        $request->session()->regenerate();

        return redirect('/admin');
    }
}
