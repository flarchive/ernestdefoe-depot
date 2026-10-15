<x-mail::plain.notification>
<x-slot:body>
{!! $translator->trans('ernestdefoe-depot.email.approved.body', [
'{title}' => $blueprint->file->title, 
'{url}' => $url->to('forum')->route('depot.file', ['slug' => $blueprint->file->slug]),
]) !!}
</x-slot:body>
</x-mail::plain.notification>
