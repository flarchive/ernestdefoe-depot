<x-mail::html.notification>
    <x-slot:body>
        {!! $formatter->convert($translator->trans('ernestdefoe-depot.email.new_version.body', [
            '{title}' => $blueprint->version->file->title, '{version}' => $blueprint->version->version, 
            '{url}' => $url->to('forum')->route('depot.file', ['slug' => $blueprint->version->file->slug]),
        ])) !!}
    </x-slot:body>
</x-mail::html.notification>
