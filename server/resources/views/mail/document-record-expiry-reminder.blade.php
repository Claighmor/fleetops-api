<x-mail-layout>
<h2 style="font-size: 18px; font-weight: 600;">
    @if($isExpired)
        Expired: {{ ucfirst($document->type) }}
    @else
        Expiring Soon: {{ ucfirst($document->type) }}
    @endif
</h2>
<p>
    @if($isExpired)
        This document <strong>expired on {{ $document->end_date?->format('d M Y') }}</strong> and needs renewing.
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
        <td style="padding: 8px 0; font-weight: 600; width: 40%; vertical-align: top;">Type</td>
        <td style="padding: 8px 0;">{{ ucfirst($document->type) }}</td>
    </tr>
    @if($document->subject_name)
    <tr>
        <td style="padding: 8px 0; font-weight: 600; vertical-align: top;">Record</td>
        <td style="padding: 8px 0;">{{ $document->subject_name }}</td>
    </tr>
    @endif
    @if($document->document_number)
    <tr>
        <td style="padding: 8px 0; font-weight: 600; vertical-align: top;">Number</td>
        <td style="padding: 8px 0;">{{ $document->document_number }}</td>
    </tr>
    @endif
    @if($document->provider)
    <tr>
        <td style="padding: 8px 0; font-weight: 600; vertical-align: top;">Provider</td>
        <td style="padding: 8px 0;">{{ $document->provider }}</td>
    </tr>
    @endif
    @if($document->jurisdiction)
    <tr>
        <td style="padding: 8px 0; font-weight: 600; vertical-align: top;">Jurisdiction</td>
        <td style="padding: 8px 0;">{{ $document->jurisdiction }}</td>
    </tr>
    @endif
    <tr>
        <td style="padding: 8px 0; font-weight: 600; vertical-align: top;">Expiry Date</td>
        <td style="padding: 8px 0;">{{ $document->end_date?->format('d M Y') }}</td>
    </tr>
</table>
@if($document->notes)
<p style="white-space: pre-line;">{{ $document->notes }}</p>
@endif
<p style="margin-top: 24px; color: #6b7280; font-size: 13px;">
    Please do not reply directly to this email. Contact the fleet manager if you have any questions.
</p>
</x-mail-layout>
