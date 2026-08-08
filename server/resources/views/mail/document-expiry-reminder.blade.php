<x-mail-layout>
<h2 style="font-size: 18px; font-weight: 600;">
    @if($isExpired)
        Expired: {{ $field->label ?? $field->name }}
    @else
        Expiring Soon: {{ $field->label ?? $field->name }}
    @endif
</h2>
<p>
    @if($isExpired)
        This document <strong>expired on {{ $expiresAt->format('d M Y') }}</strong> and needs renewing.
    @elseif($offsetDays === 0)
        This document <strong>expires today</strong>.
    @elseif($offsetDays === 1)
        This document expires <strong>tomorrow</strong>.
    @else
        This document expires in <strong>{{ $offsetDays }} days</strong>.
    @endif
</p>
<table style="width: 100%; border-collapse: collapse; margin-top: 16px; margin-bottom: 16px;">
    <tr>
        <td style="padding: 8px 0; font-weight: 600; width: 40%; vertical-align: top;">Document</td>
        <td style="padding: 8px 0;">{{ $field->label ?? $field->name }}</td>
    </tr>
    @if($subjectName)
    <tr>
        <td style="padding: 8px 0; font-weight: 600; vertical-align: top;">Record</td>
        <td style="padding: 8px 0;">{{ $subjectName }}</td>
    </tr>
    @endif
    <tr>
        <td style="padding: 8px 0; font-weight: 600; vertical-align: top;">Expiry Date</td>
        <td style="padding: 8px 0;">{{ $expiresAt->format('d M Y') }}</td>
    </tr>
</table>
@if($field->help_text)
<p style="white-space: pre-line;">{{ $field->help_text }}</p>
@endif
<p style="margin-top: 24px; color: #6b7280; font-size: 13px;">
    Please do not reply directly to this email. Contact the fleet manager if you have any questions.
</p>
</x-mail-layout>
