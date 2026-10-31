<?php

namespace ErnestDefoe\Depot\Service;

use Carbon\Carbon;
use ErnestDefoe\Depot\Model\File;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\DispatchEventsTrait;
use Flarum\Post\Event\Posted;
use Illuminate\Contracts\Events\Dispatcher;
use Flarum\Http\UrlGenerator;
use Flarum\Post\CommentPost;
use Flarum\Settings\SettingsRepositoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The file's support discussion: started as the uploader, in the tag they
 * chose, the first time the file is public.
 *
 * Its id is stored on the file. A generated post is never found again by its
 * text — posts hold parsed XML, not what was written.
 */
class SupportThread
{
    use DispatchEventsTrait;

    public function __construct(
        private UrlGenerator $url,
        private TranslatorInterface $translator,
        private SettingsRepositoryInterface $settings,
        private ExtensionManager $extensions,
        protected Dispatcher $events,
    ) {
    }

    public function start(File $file): void
    {
        if ($file->discussion_id || ! $file->wants_thread || ! $file->approved_at || ! $file->user) {
            return;
        }

        if ($this->settings->get('ernestdefoe-depot.support_thread') === '0') {
            return;
        }

        $author = $file->user;
        $link = $this->url->to('forum')->route('depot.file', ['slug' => $file->slug]);

        $body = $this->translator->trans('ernestdefoe-depot.api.thread_body', ['title' => $file->title, 'url' => $link]);
        if ($file->thread_opening) {
            $body .= "\n\n".$file->thread_opening;
        }
        // Plain text: a forum may run neither Markdown nor BBCode, and core
        // links the address on its own.
        if ($file->install) {
            $body .= "\n\n".$this->translator->trans('ernestdefoe-depot.api.thread_install')."\n";
            foreach (array_values($file->install) as $i => $step) {
                $body .= ($i + 1).'. '.$step."\n";
            }
        }

        $title = mb_substr($this->translator->trans('ernestdefoe-depot.api.thread_title', ['title' => $file->title]), 0, 80);

        $discussion = Discussion::start($title, $author);
        $discussion->save();

        $post = new CommentPost();
        $post->discussion_id = $discussion->id;
        $post->user_id = $author->id;
        $post->type = CommentPost::$type;
        $post->created_at = Carbon::now();
        $post->setContentAttribute($body, $author);
        $post->save();

        $discussion->setFirstPost($post)->setLastPost($post);
        $discussion->refreshCommentCount();
        $discussion->refreshParticipantCount();
        $discussion->save();

        if ($file->thread_tag_id && $this->extensions->isEnabled('flarum-tags')) {
            $discussion->belongsToMany(\Flarum\Tags\Tag::class, 'discussion_tag')->sync([$file->thread_tag_id]);
            $discussion->unsetRelation('tags');
        }

        // The events a discussion started through the API would fire, so tag
        // and user counts, subscriptions and other extensions all hear of it.
        $post->raise(new Posted($post, $author));
        $this->dispatchEventsFor($discussion, $author);
        $this->dispatchEventsFor($post, $author);

        $file->discussion_id = $discussion->id;
        $file->saveQuietly();
    }
}
