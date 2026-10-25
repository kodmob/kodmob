<?php

namespace App\Modules\Briefs;

use App\Core\Activity\Activity;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\Controller;
use App\Core\Http\ListQuery;
use App\Modules\Briefs\Repository\BriefRepository;
use App\Modules\Briefs\Service\SubmitBrief;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * - `POST /briefs` (herkese açık): proje brifi / iletişim talebi → 201 `{ id, received }`.
 * - `GET /briefs` (yönetici): liste, arama, durum sekmeleri.
 * - `POST /briefs/{id}/status` (yönetici): `{ status: read|replied|archived|reopen }`.
 */
final class BriefsController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $result = (new SubmitBrief)->handle($this->input($request), (string) $request->ip(), $request->userAgent());

        return $this->created($result);
    }

    public function index(Request $request): array
    {
        return (new BriefRepository)->paginate(ListQuery::fromRequest($request))->toArray() + ['record_type' => 'brief'];
    }

    public function status(Request $request, int $id): array
    {
        $briefs = new BriefRepository;
        $brief = $briefs->get($id);
        $status = (string) ($this->input($request)['status'] ?? '');
        match ($status) {
            'read' => $brief->markRead(),
            'replied' => $brief->markReplied(),
            'archived' => $brief->archive(),
            'reopen' => $brief->reopen(),
            default => throw ValidationException::field('status', 'Geçersiz durum.'),
        };
        $briefs->save($brief);
        Activity::type('briefs.status_changed');
        Activity::subject('brief', $id, $brief->toArray()['name'] ?? '');
        Activity::with(['status' => $status]);

        return $brief->toArray();
    }
}
