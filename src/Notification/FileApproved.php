<?php

namespace ErnestDefoe\Depot\Notification;

use ErnestDefoe\Depot\Model\File;
use Flarum\Database\AbstractModel;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\User\User;

/** "Your file was approved and is now public." */
class FileApproved implements AlertableInterface, BlueprintInterface, MailableInterface
{
    public function __construct(public File $file)
    {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->file;
    }

    public function getFromUser(): ?User
    {
        return null;
    }

    public function getData(): mixed
    {
        return null;
    }

    public function getEmailViews(): array
    {
        return ['text' => 'ernestdefoe-depot::emails.plain.fileApproved', 'html' => 'ernestdefoe-depot::emails.html.fileApproved'];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('ernestdefoe-depot.email.approved.subject', ['{title}' => $this->file->title]);
    }

    public static function getType(): string
    {
        return 'depotFileApproved';
    }

    public static function getSubjectModel(): string
    {
        return File::class;
    }
}
