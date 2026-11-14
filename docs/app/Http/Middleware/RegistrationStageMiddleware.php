<?php
// app/Http/Middleware/RegistrationStageMiddleware.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\User;

class RegistrationStageMiddleware
{
    public function handle(Request $request, Closure $next, int $requiredStage)
    {
        $userId = session('registration_user_id');

        if (!$userId) {
            return $this->fail($request, 'Please start registration from the beginning.', 'register');
        }

        $user = User::find($userId);

        if (!$user) {
            session()->forget('registration_user_id');
            return $this->fail($request, 'User not found. Please register again.', 'register');
        }

        if ($user->registration_completed) {
            session()->forget('registration_user_id');
            return $this->fail($request, 'Registration already completed. Please log in.', 'login');
        }

        if ($user->registration_stage < $requiredStage) {
            return $this->fail(
                $request,
                'Please complete the current stage before proceeding.',
                'register.stage' . ($user->registration_stage + 1),
                ['current_stage' => $user->registration_stage]
            );
        }

        return $next($request);
    }

    protected function fail(Request $request, string $message, string $routeName, array $extra = [])
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(array_merge([
                'success' => false,
                'message' => $message,
            ], $extra), 422);
        }

        return redirect()->route($routeName)->with('error', $message);
    }
}