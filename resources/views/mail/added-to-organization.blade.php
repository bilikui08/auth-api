<x-mail::message>
# Ahora trabajás con {{ $organization->name }}

**{{ $inviterName }}** te sumó a **{{ $organization->name }}** en Facturín. La próxima vez que ingreses vas a poder elegir esa empresa.

<x-mail::button :url="$loginUrl">
Ingresar a Facturín
</x-mail::button>

Si no esperabas este aviso, avisale a quien administra la empresa.
</x-mail::message>
