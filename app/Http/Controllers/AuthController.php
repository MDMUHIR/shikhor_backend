<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'email' => 'required|string|email|unique:users',
            'password' => 'required|string',
        ]);

        $user = new User([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        if ($user->save()) {
            $tokenResult = $user->createToken('Personal Access Token');
            $token = $tokenResult->plainTextToken;

            return $this->success('Successfully created user!', [
                'accessToken' => $token,
            ]);
        } else {
            return $this->error('Provide proper details');
        }
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $credentials = request(['email', 'password']);
        if (! Auth::attempt($credentials)) {
            return $this->error('Unauthorized');
        }

        $user = $request->user();

        if ($user->is_active === false) {
            Auth::guard('web')->logout();

            return $this->error('This account has been deactivated');
        }

        $tokenResult = $user->createToken('Personal Access Token');
        $token = $tokenResult->plainTextToken;

        return $this->success('Successfully logged in', [
            'name' => $user->name,
            'email' => $user->email,
            'type' => $user->type,
            'accessToken' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    public function user(Request $request)
    {
        return response()->json($request->user());
    }

    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'Successfully logged out',
        ]);
    }

    public function getUsers()
    {
        $users = User::query()->orderByDesc('id')->get();

        return response()->json([
            'success' => true,
            'message' => 'Users retrieved successfully',
            'data' => $users,
        ]);
    }

    public function addUser(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'email' => 'required|string|email|unique:users',
            'password' => 'required|string|min:6',
            'type' => 'required|in:customer,admin,super_admin,instructor',
            'is_active' => 'nullable|boolean',
            'headline' => 'nullable|string|max:255',
            'bio' => 'nullable|string|max:1000',
        ]);

        $user = new User([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'type' => $request->type,
            'headline' => $request->headline,
            'bio' => $request->bio,
        ]);
        $user->is_active = $request->has('is_active') ? $request->boolean('is_active') : true;

        if ($user->save()) {
            return response()->json([
                'success' => true,
                'message' => 'User added successfully',
                'data' => $user,
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add user',
            ], 500);
        }
    }

    public function deleteUser(Request $request, $id)
    {
        $user = User::find($id);

        if (! $user) {
            return $this->error('User not found', 404);
        }

        if ($user->id === $request->user()->id) {
            return $this->error('You cannot delete your own account');
        }

        // Deleting cascades to orders, payments and enrollments, so keep accounts with history.
        $hasHistory = \App\Models\Order::where('user_id', $user->id)->exists()
            || \App\Models\Enrollment::where('user_id', $user->id)->exists();

        if ($hasHistory) {
            return $this->error('This user has orders or enrollments. Deactivate the account instead of deleting it.');
        }

        if ($user->isInstructor() && \App\Models\Course::where('instructor_id', $user->id)->exists()) {
            return $this->error('Reassign this instructor\'s courses before deleting the account');
        }

        $user->tokens()->delete();
        $user->delete();

        return $this->success('User deleted successfully', null);
    }

    /** Profile photo for an account (shown on the public instructor pages). */
    public function uploadAvatar(Request $request, $id)
    {
        $request->validate(['avatar' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048']);

        $user = User::find($id);

        if (! $user) {
            return $this->error('User not found', 404);
        }

        $dir = public_path('images/avatars');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $file = $request->file('avatar');
        $name = $user->id.'-'.bin2hex(random_bytes(6)).'.'.$file->extension();
        $file->move($dir, $name);

        $user->avatar = 'images/avatars/'.$name;
        $user->save();

        return $this->success('Photo updated successfully', $user);
    }

    public function updateUser(Request $request)
    {
        $this->validate($request, [
            'id' => 'required|exists:users,id', // Ensure that the user exists
            'name' => 'required|string',
            'email' => 'required|string|email|unique:users,email,'.$request->id,
            'type' => 'required|in:customer,admin,super_admin,instructor',
            'password' => 'nullable|string|min:6',
            'is_active' => 'nullable|boolean',
            'headline' => 'nullable|string|max:255',
            'bio' => 'nullable|string|max:1000',
        ]);

        $user = User::find($request->id);
        if (! $user) {
            return $this->error('User not found', 404);
        }

        $isSelf = $user->id === $request->user()->id;

        if ($isSelf && ($request->type !== $user->type || ($request->has('is_active') && ! $request->boolean('is_active')))) {
            return $this->error('You cannot change your own role or deactivate yourself');
        }

        $user->name = $request->name;
        $user->email = $request->email;
        $user->type = $request->type;

        if ($request->has('headline')) {
            $user->headline = $request->headline;
        }

        if ($request->has('bio')) {
            $user->bio = $request->bio;
        }

        if ($request->has('is_active')) {
            $user->is_active = $request->boolean('is_active');

            if (! $user->is_active) {
                $user->tokens()->delete(); // sign them out everywhere
            }
        }

        // Update the password only if it is provided
        if ($request->filled('password')) {
            $user->password = Hash::make($request->password);
        }

        $user->save();

        return $this->success('User updated successfully', $user);
    }

    public function redirectToGoogle()
    {
        // Debug logging
        Log::info('Google OAuth Redirect Initiated', [
            'session_id' => session()->getId(),
            'session_cookie' => config('session.cookie'),
            'frontend_url' => config('app.frontend_url'),
        ]);

        // Store state in session explicitly
        session()->put('google_oauth_initiated', true);
        session()->save();

        return Socialite::driver('google')->redirect();
    }

    public function handleGoogleCallback()
    {
        // Debug logging
        Log::info('Google OAuth Callback Received', [
            'session_id' => session()->getId(),
            'query_params' => request()->all(),
            'has_state' => request()->has('state'),
            'has_code' => request()->has('code'),
        ]);

        try {
            // Handle the OAuth callback
            $googleUser = Socialite::driver('google')->user();

            // Log the user data for debugging
            Log::info('Google User Data', [
                'id' => $googleUser->id,
                'name' => $googleUser->name,
                'email' => $googleUser->email,
                'avatar' => $googleUser->avatar,
            ]);

            // Check if user exists with Google ID
            $user = User::where('google_id', $googleUser->id)->first();

            if (! $user) {
                // Check if user exists with same email
                $user = User::where('email', $googleUser->email)->first();

                if ($user) {
                    // Link Google account to existing user
                    $user->update([
                        'google_id' => $googleUser->id,
                        'avatar' => $googleUser->avatar,
                    ]);
                    Log::info('Linked Google account to existing user', ['user_id' => $user->id]);
                } else {
                    // Create new user
                    $user = User::create([
                        'name' => $googleUser->name,
                        'email' => $googleUser->email,
                        'google_id' => $googleUser->id,
                        'avatar' => $googleUser->avatar,
                        'password' => Hash::make(uniqid()), // Random password
                        'type' => 'customer', // Default user type
                    ]);
                    Log::info('Created new user from Google', ['user_id' => $user->id]);
                }
            }

            // Generate API token
            $tokenResult = $user->createToken('Personal Access Token');
            $token = $tokenResult->plainTextToken;

            // Prepare user data for frontend
            $userData = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'type' => $user->type,
                'avatar' => $user->avatar,
                'token' => $token,
                'token_type' => 'Bearer',
            ];

            // Redirect to frontend with token and user data as query parameters
            $frontendCallbackUrl = config('app.frontend_url').'/auth/google/callback';
            $userJson = json_encode($userData);

            return redirect()->away("{$frontendCallbackUrl}?token={$token}&user=".urlencode($userJson));

        } catch (\Laravel\Socialite\Two\InvalidStateException $e) {
            Log::error('InvalidStateException: '.$e->getMessage());
            $frontendCallbackUrl = config('app.frontend_url').'/auth/google/callback';

            return redirect()->away("{$frontendCallbackUrl}?error=".urlencode('Invalid state - please try again'));
        } catch (\Exception $e) {
            Log::error('Google OAuth Error: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            // Redirect to frontend with error
            $frontendCallbackUrl = config('app.frontend_url').'/auth/google/callback';

            return redirect()->away("{$frontendCallbackUrl}?error=".urlencode('Google authentication failed: '.$e->getMessage()));
        }
    }
}
