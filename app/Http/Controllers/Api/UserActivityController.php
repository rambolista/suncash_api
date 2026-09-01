<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Menu;
use App\Services\UserActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserActivityController extends Controller
{
    private const MENU_URL = '/administration/user-activity';

    public function __construct(private readonly UserActivityService $activity) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($this->userHasPermission($request->user(), self::MENU_URL, 'can_view'), 403, 'Forbidden.');

        $filters = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'action' => ['nullable', 'string'],
            'module' => ['nullable', 'string'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string'],
        ]);

        return response()->json([
            'data' => $this->activity->list($filters),
            'modules' => $this->activity->modules(),
            'actions' => UserActivityService::ACTION_LABELS,
        ]);
    }

    /**
     * Called by the frontend whenever it navigates to a new page — matches
     * the visited path against `menus.url`/`slug` (same lookup
     * `Controller::userHasPermission()` uses) and logs a "viewed" entry.
     * Silently no-ops for paths that aren't a registered menu (e.g. a
     * detail sub-page) rather than erroring, since not every route the
     * frontend renders is itself a menu item.
     */
    public function visit(Request $request): JsonResponse
    {
        $validated = $request->validate(['path' => ['required', 'string']]);

        $normalized = $this->normalizeRoute($validated['path']);
        $menu = Menu::query()
            ->where('is_title', false)
            ->where(function ($query) use ($normalized) {
                $query->where('url', $normalized)->orWhere('slug', $normalized);
            })
            ->first();

        if ($menu) {
            ActivityLog::recordMenuVisit($request->user(), $menu, $request);
        }

        return response()->json(['logged' => (bool) $menu]);
    }
}
