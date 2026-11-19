<x-mail::message>
# {{ $heading }}

{!! $bodyMarkdown !!}

<x-mail::button :url="$buttonUrl">
{{ $buttonLabel }}
</x-mail::button>
</x-mail::message>
