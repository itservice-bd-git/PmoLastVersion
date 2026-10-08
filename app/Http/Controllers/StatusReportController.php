<?php

namespace App\Http\Controllers;

use App\Services\StatusReport;
use Illuminate\Http\Request;

class StatusReportController extends Controller
{
    /** JSON for the status-report / urgent-alert popup (see components/status-report.blade.php). */
    public function show(Request $request, StatusReport $report)
    {
        return response()->json($report->build($request->user()));
    }

    /**
     * "รับทราบ" on the urgent alerts - marks the given notices read. Only ever touches the
     * signed-in user's own notifications (an id that is not theirs is silently ignored).
     */
    public function acknowledge(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'max:50'],
            'ids.*' => ['string'],
        ]);

        $count = $request->user()->unreadNotifications()->whereIn('id', $data['ids'])->update(['read_at' => now()]);

        return response()->json(['acknowledged' => $count]);
    }
}
