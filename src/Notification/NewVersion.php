<?php

namespace ErnestDefoe\Depot\Notification;

use ErnestDefoe\Depot\Model\File;
use ErnestDefoe\Depot\Model\Version;
use Flarum\Database\AbstractModel;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\User\User;

/**
 * "A file you watch has a new version."
 *
 * One alert per release, naming the version. AlertableInterface is an empty
 * marker and without it sync() writes nothing at all.
 */
class NewVersion implements AlertableInterface, BlueprintInterface, MailableInterface
{
    public function __construct(public Version $version)
    {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->version->file;
    }

    public function getFromUser(): ?User
    {
        return $this->version->file->user;
    }

    public function getData(): mixed
    {
        return ['version' => $this->version->version];
    }

    public function getEmailViews(): array
    {
        return ['text' => 'ernestdefoe-depot::emails.plain.newVersion', 'html' => 'ernestdefoe-depot::emails.html.newVersion'];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('ernestdefoe-depot.email.new_version.subject', ['{title}' => $this->version->file->title, '{version}' => $this->version->version]);
    }

    public static function getType(): string
    {
        return 'depotNewVersion';
    }

    public static function getSubjectModel(): string
    {
        return File::class;
    }
}
