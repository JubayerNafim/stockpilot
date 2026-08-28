<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SetupController extends Controller
{
    public function __construct(private SetupService $setup)
    {
    }

    public function status(): JsonResponse
    {
        return response()->json(['setup' => $this->setup->isSetup()]);
    }

    public function configureDatabase(Request $request): JsonResponse
    {
        if ($this->setup->isSetup()) {
            return response()->json(['error' => 'StockPilot is already installed'], 409);
        }

        $data = $request->validate([
            'host' => 'required|string',
            'port' => 'required|integer|min:1|max:65535',
            'database' => 'required|string|regex:/^[a-zA-Z0-9_]+$/',
            'username' => 'required|string',
            'password' => 'nullable|string',
        ]);

        $ok = $this->setup->configureDatabase(
            $data['host'],
            $data['port'],
            $data['database'],
            $data['username'],
            $data['password'] ?? '',
        );

        if (! $ok) {
            return response()->json(['error' => 'Could not connect to the database with those credentials.'], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function createAdmin(Request $request): JsonResponse
    {
        if ($this->setup->isSetup()) {
            return response()->json(['error' => 'StockPilot is already installed'], 409);
        }

        $data = $request->validate([
            'organization_name' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'password' => 'required|string|min:8',
            'currency' => 'required|string|size:3',
        ]);

        $user = $this->setup->createAdmin(
            $data['name'],
            $data['email'],
            $data['password'],
            $data['organization_name'],
            strtoupper($data['currency']),
        );

        return response()->json(['ok' => true, 'user' => $user->only(['id', 'name', 'email', 'role'])]);
    }
}
