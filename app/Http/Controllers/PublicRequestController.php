<?php

namespace App\Http\Controllers;

use App\Models\ProjectRequest;
use App\Services\NotificationService;
use Illuminate\Http\Request;

/**
 * Public (no login) "ยื่นขอโครงการ" form. Deliberately narrow: text fields only (no uploads), a honeypot,
 * and a per-IP throttle on the route. It can only ever create a *pending* request row - an admin/PM
 * decides what becomes a Project (see ProjectRequestController).
 */
class PublicRequestController extends Controller
{
    public function create()
    {
        return view('requests.public-form');
    }

    public function store(Request $request, NotificationService $notifications)
    {
        $data = $request->validate([
            'requester_name' => ['required', 'string', 'max:150'],
            'contact' => ['required', 'string', 'max:150'],
            'customer_name' => ['required', 'string', 'max:200'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:3000'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'needed_by' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        // Honeypot: a real person never sees or fills this field. Bots that do get the same "thank you"
        // page, so they learn nothing - but nothing is stored and nobody is notified.
        if (filled($request->input('website'))) {
            return redirect()->route('requests.thanks');
        }

        $projectRequest = ProjectRequest::create($data + ['status' => ProjectRequest::STATUS_PENDING]);
        $notifications->requestReceived($projectRequest);

        return redirect()->route('requests.thanks');
    }

    public function thanks()
    {
        return view('requests.thanks');
    }
}
