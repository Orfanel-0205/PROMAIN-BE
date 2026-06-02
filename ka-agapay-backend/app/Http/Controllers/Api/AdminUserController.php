<?php
//app/http/controllers/api/AdminUserController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::with('role')->paginate(20);
        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        // Placeholder for user creation logic
        return response()->json(['message' => 'Not implemented yet'], 501);
    }

    public function update(Request $request, $id): JsonResponse
    {
        return response()->json(['message' => 'Not implemented yet'], 501);
    }

    public function destroy($id): JsonResponse
    {
        return response()->json(['message' => 'Not implemented yet'], 501);
    }

    public function suspend($id): JsonResponse
    {
        $user = User::findOrFail($id);
        $user->update(['account_status' => 'suspended']);
        return response()->json(['message' => 'User suspended.']);
    }

    public function activate($id): JsonResponse
    {
        $user = User::findOrFail($id);
        $user->update(['account_status' => 'active']);
        return response()->json(['message' => 'User activated.']);
    }
}
