<?php
/**
 * Morning ledger digest — the summary strip, emailed to ledger agents only.
 * Separate from the musesys-reports order/PR emails (different hook, recipients and content).
 *
 * Schedule: weekdays 07:00 America/New_York. Implemented as a self-rescheduling single
 * Action Scheduler action so the send time stays at 07:00 local across DST changes.
 *
 * Pure helpers (next_send_at, previous_business_day, render, subject) have no WordPress calls
 * and are covered by tests/digest_test.php.
 */

if (!defined('ABSPATH') && !defined('MUSESYS_LEDGER_TESTING')) exit;

final class Ledger_Digest {
    const HOOK      = 'musesys_ledger_morning_digest';
    const SEND_HOUR = 7;

    /* =====================================================================
     * PURE PART
     * ===================================================================== */

    /** Next weekday 07:00 ET strictly after $now. */
    public static function next_send_at(DateTimeImmutable $now): DateTimeImmutable {
        $t = $now->setTimezone(new DateTimeZone(Ledger_State_Resolver::TZ));
        $c = $t->setTime(self::SEND_HOUR, 0, 0);
        if ($c <= $t) $c = $c->modify('+1 day');
        while ((int) $c->format('N') >= 6) $c = $c->modify('+1 day');
        return $c;
    }

    /** [start, end) of the previous business day in ET, relative to $now. */
    public static function previous_business_day(DateTimeImmutable $now): array {
        $d = $now->setTimezone(new DateTimeZone(Ledger_State_Resolver::TZ))->setTime(0, 0, 0)->modify('-1 day');
        while ((int) $d->format('N') >= 6) $d = $d->modify('-1 day');
        return [$d, $d->modify('+1 day')];
    }

    public static function subject(array $s, DateTimeImmutable $now): string {
        $date = $now->setTimezone(new DateTimeZone(Ledger_State_Resolver::TZ))->format('D M j');
        return sprintf('Ledger — %d need ETA, %d calls required (%s)', $s['NEEDS_ETA'], $s['CALL_REQUIRED'], $date);
    }

    /**
     * @param array  $s          summary counts (Ledger_Ajax::summarize + 'actions_prev')
     * @param string $url        ledger page URL
     * @param string $prev_label e.g. "Wed Sep 30"
     */
    public static function render(array $s, string $url, string $prev_label, DateTimeImmutable $now): string {
        $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $tiles = [
            ['Needs ETA',            $s['NEEDS_ETA'],          '#b26200'],
            ['Call required',        $s['CALL_REQUIRED'],      '#b32d2e'],
            ['Partial',              $s['partial'],            '#1d2327'],
            ['Shipped / not closed', $s['SHIPPED_NOT_CLOSED'], '#1d2327'],
            ['2+ bd late',           $s['late2'],              '#b32d2e'],
            ['Actions ' . $prev_label, $s['actions_prev'],     '#1d2327'],
        ];
        $cells = '';
        foreach ($tiles as $i => [$label, $n, $color]) {
            if ($i === 3) $cells .= '</tr><tr>';
            $cells .= '<td style="padding:6px;width:33%;">'
                . '<div style="border:1px solid #dcdcde;border-radius:6px;padding:10px 14px;background:#ffffff;">'
                . '<div style="font-size:24px;font-weight:600;line-height:1.2;color:' . $color . ';">' . $e((int) $n) . '</div>'
                . '<div style="font-size:12px;color:#646970;">' . $e($label) . '</div></div></td>';
        }
        $date = $now->setTimezone(new DateTimeZone(Ledger_State_Resolver::TZ))->format('l, F j, Y');

        return '<!doctype html><html><body style="margin:0;padding:0;background:#f0f0f1;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f0f0f1;"><tr><td align="center" style="padding:24px 12px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1d2327;">'
            . '<tr><td style="padding:0 6px 4px;font-size:20px;font-weight:600;">Ledger — morning summary</td></tr>'
            . '<tr><td style="padding:0 6px 12px;font-size:13px;color:#646970;">' . $e($date) . '</td></tr>'
            . '<tr><td><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>' . $cells . '</tr></table></td></tr>'
            . '<tr><td align="center" style="padding:20px 6px 8px;">'
            . '<a href="' . $e($url) . '" style="display:inline-block;background:#2271b1;color:#ffffff;text-decoration:none;font-weight:600;padding:12px 24px;border-radius:4px;">Open the Ledger</a>'
            . '</td></tr>'
            . '<tr><td align="center" style="padding:0 6px;font-size:12px;color:#646970;">If you are asked to sign in, you will be taken straight back to the ledger.<br>'
            . '<a href="' . $e($url) . '" style="color:#2271b1;">' . $e($url) . '</a></td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /* =====================================================================
     * WORDPRESS PART
     * ===================================================================== */

    public static function init(): void {
        add_action(self::HOOK, [__CLASS__, 'run']);
        add_action('init', [__CLASS__, 'ensure_scheduled'], 31);
    }

    public static function ensure_scheduled(): void {
        if (!function_exists('as_has_scheduled_action') || get_transient('musesys_ledger_digest_ok')) return;
        if (!as_has_scheduled_action(self::HOOK, [], Ledger_Scheduler::GROUP)) {
            self::schedule_next();
        }
        set_transient('musesys_ledger_digest_ok', 1, HOUR_IN_SECONDS);
    }

    private static function schedule_next(): void {
        $at = self::next_send_at(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        as_schedule_single_action($at->getTimestamp(), self::HOOK, [], Ledger_Scheduler::GROUP);
    }

    /** Scheduled entry point: send, then schedule tomorrow's run (next weekday). */
    public static function run(): void {
        try {
            self::send();
        } finally {
            self::schedule_next();
        }
    }

    /** Builds and sends the digest. Returns the number of recipients mailed. Callable manually for testing. */
    public static function send(): int {
        $emails = [];
        foreach (get_users(['role' => Ledger_Access::ROLE_AGENT, 'fields' => ['user_email']]) as $u) {
            if (is_email($u->user_email)) $emails[] = $u->user_email;
        }
        if (!$emails) {
            error_log('MuseSys Ledger digest: no users with the ' . Ledger_Access::ROLE_AGENT . ' role; nothing sent.');
            return 0;
        }

        // Fresh numbers: resolve open rows first, so overdue counts reflect this morning, not the last reconcile.
        Ledger_Projector::resolve_all(false);

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $s   = Ledger_Ajax::summarize(Ledger_Ajax::rows_for(true)); // exactly what the agent sees
        [$from, $to] = self::previous_business_day($now);
        $utc = new DateTimeZone('UTC');
        $s['actions_prev'] = Ledger_Events::ui_count_between(
            $from->setTimezone($utc)->format('Y-m-d H:i:s'),
            $to->setTimezone($utc)->format('Y-m-d H:i:s')
        );

        $html = self::render($s, Ledger_Access::page_url(), $from->format('D M j'), $now);
        $sent = 0;
        foreach ($emails as $to_addr) {
            if (wp_mail($to_addr, self::subject($s, $now), $html, ['Content-Type: text/html; charset=UTF-8'])) $sent++;
        }
        return $sent;
    }
}
