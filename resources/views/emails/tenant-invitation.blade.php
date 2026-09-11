<x-mail::message>
# მოწვევა — {{ $tenantName }}

გეპატიჟებით {{ $tenantName }}-ის სასკოლო პორტალში, როლით: **{{ $roleLabel }}**.

დააჭირეთ ღილაკს ანგარიშის გასააქტიურებლად. ბმული მოქმედია {{ $expiresAt->translatedFormat('d F Y, H:i') }}-მდე.

<x-mail::button :url="$acceptUrl">
მოწვევის მიღება
</x-mail::button>

თუ ეს მოწვევა თქვენ არ ეხებათ, უბრალოდ დააიგნორეთ ეს წერილი.

{{ config('app.name') }}
</x-mail::message>
