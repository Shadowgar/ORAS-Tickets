# Zoom Phone Join Instructions Design

## Goal

Make Zoom telephone access understandable for attendees using either a mobile
phone or a landline. Preserve Zoom's exact invitation values while replacing
the raw one-tap strings with clear instructions.

## Email Design

Approved virtual RSVP and paid virtual-ticket emails will distinguish:

- App/Web Passcode
- Meeting ID
- Phone Passcode
- Mobile one-tap dialing
- Landline or manual dialing

Mobile users will receive a short explanation and one call button for each
Zoom-provided one-tap number. Each button will use the complete Zoom-generated
`tel:` sequence so the meeting ID and numeric phone passcode are entered
automatically.

Landline and manual-dial users will receive these numbered instructions:

1. Dial one of the displayed Zoom telephone numbers.
2. Enter the Meeting ID followed by `#`.
3. Press `#` to skip the participant ID.
4. Enter the numeric Phone Passcode followed by `#`.

The manual section will show clean telephone numbers rather than Zoom's raw
one-tap sequences. The existing local-number link remains available.

## Compatibility And Safety

- Do not alter Zoom meeting credentials or invitation data.
- Escape all generated links, labels, and values for HTML email output.
- Fall back to the existing invitation display if a one-tap line cannot be
  parsed safely.
- Do not expose virtual access details to pending or rejected RSVPs.

## Verification

Extend the Zoom integration harness to verify that both email paths contain
mobile and manual dialing guidance. Verify syntax, PHPCS, PHPStan, version
consistency, Zoom integration checks, and repository whitespace.
