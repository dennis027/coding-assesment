<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * POST /api/register
     * Register a new merchant profile with clean JSON error formatting.
     */
    public function register(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name'          => ['required', 'string', 'max:255'],
                'business_name' => ['required', 'string', 'max:255'], // Added to satisfy DB constraints
                'email'         => ['required', 'string', 'email', 'max:255', 'unique:merchants,email'],
                'password'      => ['required', 'string', 'min:8'],
            ]);

            $merchant = Merchant::create([
                'name'          => $validated['name'],
                'business_name' => $validated['business_name'],
                'email'         => $validated['email'],
                'password'      => $validated['password'], // Handled automatically by the 'hashed' model cast
            ]);

            $token = $merchant->createToken('auth_token')->plainTextToken;

            return response()->json([
                'access_token' => $token,
                'token_type'   => 'Bearer',
                'merchant'     => [
                    'id'            => $merchant->id,
                    'name'          => $merchant->name,
                    'business_name' => $merchant->business_name,
                    'email'         => $merchant->email,
                ]
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors'  => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Registration Error: ' . $e->getMessage());
            return response()->json([
                'message' => 'An unexpected error occurred during registration.'
            ], 500);
        }
    }

    /**
     * POST /api/login
     * Authenticate an existing merchant.
     */
    public function login(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'email'    => ['required', 'email'],
                'password' => ['required'],
            ]);

            $merchant = Merchant::where('email', $request->email)->first();

            if (! $merchant || ! Hash::check($request->password, $merchant->password)) {
                return response()->json([
                    'message' => 'Invalid credentials.'
                ], 401);
            }

            $token = $merchant->createToken('auth_token')->plainTextToken;

            return response()->json([
                'access_token' => $token,
                'token_type'   => 'Bearer',
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors'  => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Login Error: ' . $e->getMessage());
            return response()->json([
                'message' => 'An unexpected error occurred during authentication.'
            ], 500);
        }
    }

    /**
     * POST /api/logout
     * Invalidate the merchant's current authentication session token.
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            if ($request->user()) {
                $request->user()->currentAccessToken()->delete();
            }

            return response()->json([
                'message' => 'Tokens revoked successfully'
            ], 200);
        } catch (\Exception $e) {
            Log::error('Logout Error: ' . $e->getMessage());
            return response()->json([
                'message' => 'An error occurred during logout.'
            ], 500);
        }
    }
}