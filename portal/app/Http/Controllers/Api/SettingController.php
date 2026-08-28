<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class SettingController extends Controller
{
    public function __construct(private SettingService $settings)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;
        $org = Organization::find($orgId);

        return response()->json([
            'organization' => [
                'name' => $org?->name,
                'currency' => $org?->currency,
                'timezone' => $org?->timezone,
                'low_stock_email' => $org?->low_stock_email,
            ],
            'settings' => $this->settings->all($orgId),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;
        $org = Organization::findOrFail($orgId);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'currency' => 'sometimes|string|size:3',
            'timezone' => 'sometimes|string|max:64',
            'low_stock_email' => 'sometimes|nullable|email',
            'allow_negative_stock' => 'sometimes|boolean',
            'warning_cooldown_hours' => 'sometimes|integer|min:0',
            'status_map' => 'sometimes|array',
        ]);

        $org->fill($data)->save();

        foreach (['allow_negative_stock', 'warning_cooldown_hours', 'status_map'] as $key) {
            if (array_key_exists($key, $data)) {
                $this->settings->set($orgId, $key, $data[$key]);
            }
        }

        return response()->json(['ok' => true, 'settings' => $this->settings->all($orgId)]);
    }

    /** Send a test email to the configured low-stock address (synchronous). */
    public function testEmail(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;
        $org = Organization::findOrFail($orgId);
        $email = $org->low_stock_email;

        if (! $email) {
            return response()->json(['error' => 'No low-stock email is configured in Settings first.'], 422);
        }

        try {
            Mail::raw(
                "This is a test email from StockPilot.\n\nYour low-stock alerts will be sent to this address. If you received this, mail delivery is working.\n\n— StockPilot",
                function ($message) use ($email) {
                    $message->to($email)
                        ->subject('StockPilot test email — '.now()->format('Y-m-d H:i:s'))
                        ->from(config('mail.from.address'), config('mail.from.name'));
                }
            );
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Mail send failed: '.$e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'sent_to' => $email]);
    }
}
