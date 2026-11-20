<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Allows admins and instructors (course-management API). */
class Staff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->is_active === false || ! $user->canManageCourses()) {
            return response(['status' => false, 'error' => true, 'message' => 'You are not authorized'], 403);
        }

        return $next($request);
    }
}
