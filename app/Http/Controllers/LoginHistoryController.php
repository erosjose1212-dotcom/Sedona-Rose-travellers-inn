<?php

namespace App\Http\Controllers;

use App\Services\AuthenticationAuditService;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LoginHistoryController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'event' => ['nullable', Rule::in(AuthenticationAuditService::EVENTS)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => $request->filled('from')
                ? ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']
                : ['nullable', 'date_format:Y-m-d'],
        ]);
        $query = DB::table('audit_logs')->whereIn('event', AuthenticationAuditService::EVENTS);

        if (! empty($filters['q'])) {
            $search = '%'.$filters['q'].'%';
            $query->where(fn (Builder $query) => $query->where('new_values->name', 'like', $search)
                ->orWhere('new_values->email', 'like', $search)->orWhere('ip_address', 'like', $search));
        }
        if (! empty($filters['event'])) {
            $query->where('event', $filters['event']);
        }
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from'].' 00:00:00');
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<', Carbon::parse($filters['to'])->addDay()->startOfDay());
        }

        $events = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(30)->withQueryString();
        $events->getCollection()->transform(function (object $event): object {
            $event->details = json_decode($event->new_values ?? '{}', true, 512, JSON_THROW_ON_ERROR);

            return $event;
        });

        return view('admin.login-history', compact('events', 'filters'));
    }
}
