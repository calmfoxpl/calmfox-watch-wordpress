# Calmfox Watch for WordPress

**English** · [Polski](README.pl.md)

Inside-out monitoring of a WordPress site for [Calmfox Watch](https://watch.calmfox.net):
service health, basic security hygiene and update history.

The plugin exposes one secret health endpoint that Calmfox Watch polls. The model
is "pull": apart from registration, pairing and disconnecting, the plugin sends
nothing on its own. Everything else is an answer to a question from the monitoring.

## Screenshots

The plugin's interface is currently in Polish.

![Dashboard widget](docs/screenshots/dashboard-widget.png)

*The health widget on the WordPress dashboard: score ring, check counters and the most urgent issues.*

![Plugin screen](docs/screenshots/plugin-screen.png)

*The plugin screen: connection status and the site's health score by area.*

![Service health](docs/screenshots/service-health.png)

*Service health, with the hosting account's disk limit entered by hand.*

![Update history](docs/screenshots/update-history.png)

*Update history: every core, plugin and theme update with the version before and after.*

## Requirements

| Component | Range |
| --- | --- |
| WordPress | 6.0 and later (tested up to 7.0) |
| PHP | 7.4 and later |
| Multisite | supported |

## What it does

- **Service health**: database, disk space, connection to the mail server (SMTP),
  the WP-Cron schedule, object cache (Redis/Memcached), Elasticsearch (ElasticPress).
  Results are published by a secret endpoint polled by the monitoring; a failed
  service opens an incident in the Calmfox Watch panel.
- **Security hygiene**: simple, honest configuration checks (salts, file editor,
  XML-RPC, file permissions, PHP version, pending updates). A check that turns red
  also says what should be there instead and, where the fix fits in one command,
  shows it ready to copy. This is NOT a security audit or a malware scanner.
- **One-click fixes**: permissions of `wp-config.php` and of world-writable
  directories, switching off XML-RPC and the dashboard file editor. Every fix is
  described before you click, and the switches are reversible. Commands shown as
  hints never run by themselves.
- **Update history**: every core/plugin/theme update with the version before and
  after, collected from the moment the plugin is installed. In the panel it lines up
  with the incident timeline ("what changed before the outage").
- **Dashboard widget**: status, check counters and at most three most urgent issues,
  for the person who came to the dashboard for something else and is walking past
  an outage.
- **Free account straight away**: activated from the plugin, sign-in by e-mail link,
  no password.

## Installation

1. Upload and activate the plugin.
2. Open **Calmfox Watch** in the dashboard menu (the item with an icon, right below
   "Dashboard").
3. Connect the site, one of three ways:
   - **Connect through watch.calmfox.net**: one button. You sign in (or sign up) in
     the panel, pick an organisation and come back with the plugin paired. The site
     does not have to exist in the panel beforehand.
   - **Free plan**: enter an e-mail address and the account is created on the spot.
   - **Installation token**: paste the token from the Integrations screen in the
     panel. This is the fallback when the redirect is not an option.

## The health endpoint

The plugin registers the REST route `calmfox/v1/health`. It is the monitoring that
asks, not the other way round, so there is no session to present: authorisation is
a secret in the `key` parameter, compared with `hash_equals`, and without it the
endpoint answers 403. A failing service switches the response to HTTP 503. The
`section=security` parameter selects the security section, which the monitoring
asks for once a day; service health is polled every minute.

Every response is signed: the panel sends a one-time nonce with each poll and the
plugin signs the response with the installation key (HMAC-SHA256 in a header). That
shows whether the result was really produced now, on this site, or whether somebody
placed a static file under that address or replays an old copy. A response without
a valid signature does not overwrite the site's state and opens an event in the panel.

Rotating the secret (the "Wymień klucz" button) works with a 15-minute window: the
new secret is valid at once and the previous one is honoured for another quarter of
an hour, so a failed switch-over in the panel does not break the monitoring.

## Custom checks

Services only the site's developer knows about (RabbitMQ, queues, other daemons) are
added with the `calmfox_watch_health_checks` filter:

```php
add_filter('calmfox_watch_health_checks', function (array $checks) {
    $ok = @fsockopen('127.0.0.1', 5672, $errno, $error, 2);
    $checks[] = array(
        'id'     => 'rabbitmq',
        'status' => $ok ? 'ok' : 'fail',
        'label'  => 'RabbitMQ',
        'detail' => $ok ? null : 'The broker is not accepting connections.',
    );
    if ($ok) {
        fclose($ok);
    }
    return $checks;
});
```

Statuses are `ok`, `warn` and `fail`. Keep a short, hard timeout: the endpoint is
polled every minute and must not slow the site down.

## Multisite

A multisite network is supported: every subsite connects to the panel separately
(its own secret, its own address, its own site in the panel), and the number of
accounts with full privileges also includes the network's super admins, because
they have access to every subsite. Update history is shared across the network,
just as plugins and themes are. Network activation is recommended: when the plugin
is active only on a chosen subsite, the history records only the updates that
happened to run in its context. On multisite the installation size refers to the
whole network and is described that way.

## Privacy

The plugin sends to Calmfox only: the site's domain, the e-mail address you enter
(when activating an account) and the diagnostic data described above (service
statuses, versions, numbers of pending updates, update history, names of active
plugins and the state of automatic updates). The names of active plugins are needed
so that the panel can say which plugin was deactivated instead of a generic
"something changed". Administrator logins are not sent: the panel receives their
number and a one-way fingerprint of the set of accounts, which reveals a change in
its composition but not who the people are. No content, no users, no passwords.

## Limitations

- The SMTP test checks the connection to the mail server, not actual delivery.
- Disk space: shared hosting does not reveal the account's limit (PHP reports the
  whole server volume), so you enter it in the plugin's settings. Without it the
  plugin shows only the size of the installation, without guessing.
- Update history starts when the plugin is installed. Earlier changes cannot be
  reconstructed.

## Licence

GPL-2.0-or-later, see [LICENSE](LICENSE).
