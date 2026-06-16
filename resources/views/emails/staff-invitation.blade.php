<x-mail::message>
# You're Invited

You've been invited to manage **{{ $agencyName }}** on the Navigo Console.

**Role assigned:** {{ str_replace('_', ' ', ucwords($role, '_')) }}

This invitation expires on **{{ $expiresAt }}**.

<x-mail::button :url="$acceptUrl">
Accept Invitation & Create Account
</x-mail::button>

If you did not expect this invitation, you can ignore this email.

Thanks,
{{ config('app.name') }}
</x-mail::message>
