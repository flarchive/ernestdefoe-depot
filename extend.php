<?php

/*
 * Depot — a downloads section for Flarum 2.
 */

use ErnestDefoe\Depot\Api\Controller;
use ErnestDefoe\Depot\Api\Resource\FileResource;
use ErnestDefoe\Depot\Console\SyncGitHubCommand;
use ErnestDefoe\Depot\Content\FileMeta;
use ErnestDefoe\Depot\Http\DownloadController;
use ErnestDefoe\Depot\Http\StylesheetController;
use ErnestDefoe\Depot\Notification\FileApproved;
use ErnestDefoe\Depot\Notification\NewVersion;
use ErnestDefoe\Depot\Storage\Store;
use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\Foundation\Paths;
use Flarum\Http\UrlGenerator;
use Illuminate\Console\Scheduling\Event;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->jsDirectory(__DIR__.'/js/dist/forum')
        // Every page route also exists here, so a pasted link loads the page
        // instead of the forum's 404. The file page builds its own link card.
        ->route('/downloads', 'depot.index')
        ->route('/downloads/c/{slug}', 'depot.category')
        ->route('/downloads/f/{slug}', 'depot.file', FileMeta::class),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    // Store extensions add their ProductAccess classes here (Extend\Products).
    (new Extend\ServiceProvider())->register(ErnestDefoe\Depot\Products\ProductServiceProvider::class),

    (new Extend\View())->namespace('ernestdefoe-depot', __DIR__.'/views'),

    /*
     * Two disks. Files are PRIVATE — under storage/, reachable only through
     * the download route — and screenshots are public assets. Either can be
     * pointed at S3 or R2 by overriding the driver.
     */
    (new Extend\Filesystem())
        ->disk(Store::FILES, fn (Paths $paths) => [
            'root' => "$paths->storage/depot",
            'visibility' => 'private',
        ])
        ->disk(Store::MEDIA, fn (Paths $paths, UrlGenerator $url) => [
            'root' => "$paths->public/assets/depot",
            'url' => $url->to('forum')->path('assets/depot'),
            // Explicit: left to the umask, directories come out 0700 and every
            // screenshot is a 403 on hosts where PHP and the web server differ.
            'visibility' => 'public',
            'directory_visibility' => 'public',
        ]),

    (new Extend\Routes('forum'))
        ->get('/downloads/get/{id:[0-9]+}', 'depot.download', DownloadController::class)
        // Depot's page styles, fetched with the page chunk instead of riding
        // in every visitor's forum.css. No .css in the path: nginx configs
        // (Flarum's own example among them) serve *.css as static files and
        // never pass the request to PHP.
        ->get('/downloads/stylesheet', 'depot.css', StylesheetController::class),

    (new Extend\Routes('api'))
        ->get('/depot/browse', 'depot.browse', Controller\BrowseController::class)
        ->get('/depot/file', 'depot.show', Controller\ShowFileController::class)
        ->get('/depot/latest/{slug}', 'depot.latest', Controller\LatestController::class)
        ->post('/depot/files', 'depot.files.create', Controller\SaveFileController::class)
        ->post('/depot/files/{id:[0-9]+}', 'depot.files.update', Controller\SaveFileController::class)
        ->delete('/depot/files/{id:[0-9]+}', 'depot.files.delete', Controller\DeleteFileController::class)
        ->post('/depot/files/{id:[0-9]+}/versions', 'depot.versions.create', Controller\SaveVersionController::class)
        ->delete('/depot/versions/{id:[0-9]+}', 'depot.versions.delete', Controller\DeleteVersionController::class)
        ->post('/depot/files/{id:[0-9]+}/approve', 'depot.files.approve', Controller\ApproveFileController::class)
        ->post('/depot/files/{id:[0-9]+}/feature', 'depot.files.feature', Controller\FeatureFileController::class)
        ->post('/depot/files/{id:[0-9]+}/watch', 'depot.files.watch', Controller\WatchFileController::class)
        ->post('/depot/files/{id:[0-9]+}/review', 'depot.reviews.save', Controller\SaveReviewController::class)
        ->delete('/depot/reviews/{id:[0-9]+}', 'depot.reviews.delete', Controller\DeleteReviewController::class)
        ->post('/depot/reviews/{id:[0-9]+}/reply', 'depot.reviews.reply', Controller\ReplyReviewController::class)
        ->post('/depot/reviews/{id:[0-9]+}/helpful', 'depot.reviews.helpful', Controller\HelpfulReviewController::class)
        ->delete('/depot/screenshots/{id:[0-9]+}', 'depot.screenshots.delete', Controller\DeleteScreenshotController::class)
        // Administration: the controller asserts admin itself.
        ->get('/depot/categories', 'depot.categories', Controller\CategoriesController::class)
        ->post('/depot/categories', 'depot.categories.create', Controller\CategoriesController::class)
        ->post('/depot/categories/order', 'depot.categories.order', Controller\CategoriesController::class)
        ->patch('/depot/categories/{id:[0-9]+}', 'depot.categories.update', Controller\CategoriesController::class)
        ->delete('/depot/categories/{id:[0-9]+}', 'depot.categories.delete', Controller\CategoriesController::class),

    // Whether to show the navigation link at all, decided by the server.
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('canViewDepot')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('depot.view')),
            Schema\Boolean::make('canUploadToDepot')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('depot.upload')),
        ]),

    // For notifications: a subject without a resource 500s /api/notifications.
    new Extend\ApiResource(FileResource::class),

    (new Extend\Notification())
        ->type(NewVersion::class, ['alert', 'email'])
        ->type(FileApproved::class, ['alert', 'email']),

    (new Extend\Console())
        ->command(SyncGitHubCommand::class)
        ->schedule(SyncGitHubCommand::class, function (Event $event): void {
            $event->hourly()->withoutOverlapping();
        }),

    (new Extend\Settings())
        ->default('ernestdefoe-depot.count_window', '24')
        ->default('ernestdefoe-depot.max_upload_mb', '250')
        ->default('ernestdefoe-depot.uploads_per_hour', '10')
        ->default('ernestdefoe-depot.support_thread', '1')
        ->default('ernestdefoe-depot.github_sync', '1')
        ->serializeToForum('depotMaxUploadMb', 'ernestdefoe-depot.max_upload_mb', fn ($v) => (int) ($v ?: 250))
        ->serializeToForum('depotSupportThread', 'ernestdefoe-depot.support_thread', fn ($v) => $v !== '0'),
];
