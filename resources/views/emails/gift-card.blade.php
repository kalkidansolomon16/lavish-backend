{{-- "Surprise! You've got a Lavish gift card" — sent to the recipient when the card is activated.
     Table layout + inline styles so it looks right in Gmail, Outlook and phone mail apps. --}}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background:#f3efe9;font-family:Helvetica,Arial,sans-serif;color:#2c3a3b">
<div style="display:none;max-height:0;overflow:hidden">{{ $from }} sent you a Lavish Medical Spa gift card{{ $message ? ' — “'.$message.'”' : '' }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3efe9">
<tr><td align="center" style="padding:28px 12px">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:18px;overflow:hidden">

  {{-- Surprise header --}}
  <tr><td align="center" style="background:#0b7177;padding:34px 24px 26px">
    <div style="font-size:44px;line-height:1">🎁</div>
    <div style="margin-top:12px;font:600 11px Helvetica,Arial,sans-serif;letter-spacing:3px;color:#cfe7e4">SURPRISE!</div>
    <div style="margin-top:8px;font:400 30px Georgia,'Times New Roman',serif;color:#ffffff;line-height:1.2">{{ $name ? $name.', you' : 'You' }}’ve got a gift</div>
    <div style="margin-top:8px;font-size:15px;color:#e3f1ef">from <b style="color:#ffffff">{{ $from }}</b></div>
  </td></tr>

  {{-- The card --}}
  <tr><td style="padding:26px 24px 8px">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0b7177;background-image:linear-gradient(135deg,#0e8389,#06484c);border-radius:16px">
      <tr><td style="padding:20px 22px 6px">
        <div style="font:500 20px Georgia,serif;color:#ffffff">Lavish</div>
        <div style="font-size:9px;letter-spacing:2px;color:#d9ece9">MEDICAL SPA</div>
      </td></tr>
      <tr><td style="padding:14px 22px 4px">
        <div style="font:italic 400 30px Georgia,serif;color:#ffffff">Gift Card</div>
        <div style="width:40px;height:2px;background:#c9a48c;margin-top:4px"></div>
      </td></tr>
      <tr><td style="padding:10px 22px 22px">
        <div style="font:600 26px Helvetica,Arial,sans-serif;color:#ffffff">{{ $value }}</div>
        <div style="font-size:12px;color:#d9ece9;margin-top:2px">Valid {{ $period }}</div>
      </td></tr>
    </table>
  </td></tr>

  {{-- The personal message --}}
  @if ($message)
  <tr><td style="padding:14px 24px 0">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f7ede6;border-radius:14px">
      <tr><td style="padding:18px 20px">
        <div style="font:italic 400 20px Georgia,serif;color:#7a5440;line-height:1.4">“{{ $message }}”</div>
        <div style="margin-top:8px;font-size:13px;color:#9c735c">— {{ $from }}</div>
      </td></tr>
    </table>
  </td></tr>
  @endif

  {{-- How to use --}}
  <tr><td style="padding:24px 24px 4px">
    <div style="font:400 20px Georgia,serif;color:#0b7177">Treat yourself</div>
    <p style="margin:8px 0 0;font-size:14px;line-height:1.6">Your gift is ready to use for any Lavish treatment {{ $period }}. Book a visit, then show this email{{ $physical ? ' or bring your gift card' : '' }} at the Lavish front desk — they scan the barcode below and the gift covers your treatment.</p>
  </td></tr>

  {{-- Card number + barcode --}}
  @if ($code)
  <tr><td align="center" style="padding:18px 24px 6px">
    <table role="presentation" cellpadding="0" cellspacing="0" style="border:1px dashed #c9a48c;border-radius:12px">
      <tr><td align="center" style="padding:16px 18px 12px">
        <div style="font-size:11px;letter-spacing:2px;color:#0b7177;font-weight:700">YOUR GIFT CARD NUMBER</div>
        <div style="margin:8px 0 12px;font:700 18px 'Courier New',monospace;letter-spacing:2px;color:#1c2b2c">{{ $code }}</div>
        {!! $barcode !!}
      </td></tr>
    </table>
  </td></tr>
  @endif

  <tr><td align="center" style="padding:22px 24px 6px">
    <a href="{{ $appUrl }}" style="display:inline-block;background:#c9a48c;color:#1c2b2c;text-decoration:none;font-weight:600;font-size:14px;padding:12px 22px;border-radius:99px">Get the Lavish app</a>
    <div style="margin-top:8px;font-size:12px;color:#8a9596">Add the card in the app (Gift cards → Add a card) to see your balance any time.</div>
  </td></tr>

  {{-- Footer --}}
  <tr><td style="padding:24px 24px 0">
    <div style="height:1px;background:#ece7e1"></div>
  </td></tr>
  <tr><td align="center" style="padding:16px 24px 26px;font-size:12px;color:#8a9596;line-height:1.6">
    Lavish Medical Spa · Addis Ababa · Adama · Dire Dawa<br>
    +251 972 444 447 · lavishmedicalspa.com<br>
    <span style="font-size:11px">Valid once activated. Use for services or products; any remaining balance stays on the card. Not exchangeable for cash.</span>
  </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
