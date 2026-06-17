<?php
//app/Http/Controllers/Api/AdminController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\QueueTicket;
use App\Models\TelemedicineSession;
use Illuminate\Http\JsonResponse;

class AdminController extends Controller
{
    public function systemStats(): JsonResponse
    {
        return response()->json([
            'total_users' => User::count(),
            'total_tickets' => QueueTicket::count(),
            'total_sessions' => TelemedicineSession::count(),
            'uptime' => '100%', // Mock
        ]);
    }
}
