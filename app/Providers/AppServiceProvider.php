<?php

namespace App\Providers;

use App\Managers\Exports\ConversationExportManager;
use App\Managers\Favicons\FaviconManager;
use App\Managers\Imports\ConversationImportManager;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Project;
use App\Policies\AttachmentPolicy;
use App\Policies\ConversationPolicy;
use App\Policies\ProjectPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ConversationExportManager::class);
        $this->app->singleton(FaviconManager::class);
        $this->app->singleton(ConversationImportManager::class);
    }

    public function boot(): void
    {
        Model::unguard();
        Model::shouldBeStrict(! $this->app->isProduction());
        Date::use(CarbonImmutable::class);
        DB::prohibitDestructiveCommands($this->app->isProduction());
        RequestException::dontTruncate();

        Relation::enforceMorphMap([]);

        if (Config::get('livewire.temporary_file_upload.rules') === null) {
            Config::set('livewire.temporary_file_upload.rules', [
                'required',
                'file',
                'max:'.Config::integer('imports.max_upload_kilobytes'),
            ]);
        }

        Config::set(
            'livewire.temporary_file_upload.max_upload_time',
            Config::integer('imports.temporary_upload_minutes'),
        );

        Gate::policy(Conversation::class, ConversationPolicy::class);
        Gate::policy(Attachment::class, AttachmentPolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
    }
}
