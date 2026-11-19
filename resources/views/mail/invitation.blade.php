<x-mail::message>
# Te invitaron a {{ config('app.name') }}

**{{ $inviterName }}** te invitó a trabajar con los datos de **{{ $organization->name }}** en {{ config('app.name') }}.

<x-mail::button :url="$acceptUrl">
Aceptar la invitación
</x-mail::button>

Vas a elegir tu nombre y una contraseña. El enlace vale {{ $validDays }} días y se puede usar una sola vez.

Si no esperabas esta invitación, ignorá este mensaje: sin aceptarla no se crea ninguna cuenta.
</x-mail::message>
