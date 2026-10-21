<?php

namespace App\Modules\Jarvis\Http;

use App\Core\Activity\Activity;
use App\Core\Ai\AiSettings;
use App\Core\Http\Controller;
use App\Core\Modules;
use App\Modules\Domains\Jobs\JobRunner;
use App\Modules\Jarvis\Skills\SkillRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Jarvis uçları (km_use_jarvis). Domain arama ayrı modüldür (Domains\Http\DomainsController).
 *
 *   GET    /jarvis                        beceriler, etkin işler (domain arama açıksa), yapay zekâ durumu
 *   POST   /jarvis/skills/{name}          beceriyi doğrudan çalıştır (gövde = girdi)
 */
final class JarvisController extends Controller
{
    public function overview(): array
    {
        return [
            'skills' => SkillRegistry::describe(Auth::user()),
            'jobs' => Modules::enabled('domains') ? array_map(static fn ($j) => $j->toArray(), (new JobRunner)->repository()->active()) : [],
            'ai' => AiSettings::view(),
        ];
    }

    public function skill(Request $request, string $name): mixed
    {
        SkillRegistry::authorize($name, $request->user());
        Activity::type('jarvis.skill_run');
        Activity::with(['skill' => SkillRegistry::get($name)->title()]);

        return SkillRegistry::get($name)->run($this->input($request));
    }
}
