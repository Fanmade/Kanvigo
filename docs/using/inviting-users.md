# Inviting users

Public registration is disabled by design: an account exists only because
someone invited it.

## Sending an invitation

Users holding the `invite-users` permission send a signed invitation link by
email, from the bottom of the sidebar. The link carries the invitee's address,
expires after a while, and can be resent or revoked from the user-administration
area while it is still pending.

Opening the link lets the invitee set their name and password, after which they
land on the security setup page (two-factor and passkeys).

## Where the mail goes

With `MAIL_MAILER=log` — the default — no mail is sent and the invitation link
is written to `storage/logs/laravel.log`, which is what you want locally.
Configure a real mail driver in production, or invitations will never reach
anyone.

## After the invitation

An invitee starts with no project access. Add them to a project and give them a
role from the project's member panel; membership alone grants nothing, since
every permission check resolves through the project's roles.
