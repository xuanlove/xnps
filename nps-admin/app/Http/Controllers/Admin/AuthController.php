<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * 管理端登录（Sanctum token，仅 is_admin 用户可换取）（管理 UI）
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $validated['email'])->first();
        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => '账号或密码错误']);
        }
        if (! $user->is_admin) {
            return response()->json(['error' => 'admin only'], 403);
        }

        // 管理 token 12 小时过期，ability=admin
        $token = $user->createToken('admin-ui', ['admin'], now()->addHours(12))->plainTextToken;

        return response()->json([
            'data' => ['user' => $user, 'token' => $token],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['data' => ['logout' => true]]);
    }
}
