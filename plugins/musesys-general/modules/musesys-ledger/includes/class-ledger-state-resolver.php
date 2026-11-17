<?php
/**
 * Ledger state resolver — deterministic and pure.
 *
 * No WordPress calls live here so the rules can be unit-tested from the PHP CLI
 * (tests/resolver_test.php). Nothing in the UI sets state; this class does.
 * Spec: LEDGER_SPEC.md §5. Bump VERSION whenever a rule changes (§4.4).
 */

if (!defined('ABSPATH') && !defined('MUSESYS_LEDGER_TESTING')) exit;

final class Ledger_State_Resolver {
    const VERSION = 1;

    const TZ            = 'America/New_York';
    const TRANSFER_TRX  = '99999';
    const OPEN_STATUSES     = ['processing', 'on-hold', 'pending', 'partially-shipped', 'order-placed'];
    const ACTIONABLE        = ['SHIPPED_NOT_CLOSED', 'CALL_REQUIRED', 'NEEDS_ETA'];
    const COMING_DUE_BDAYS  = 2;

    /**
     * @param array $row      Projected inputs: order_status, trx, po_number, is_drop_ship, qty_ordered,
     *                        qty_shipped, logistics_status, eta (Y-m-d), confirmed_at (UTC Y-m-d H:i:s),
     *                        confirmed_at_source.
     * @param array $contacts Order-scoped human contact events: [['action' => 'email_logged'|'call_logged',
     *                        'created_at' => UTC Y-m-d H:i:s], ...]. Other actions are ignored.
     * @return array{state:string, reason:?string, due_at:?string, overdue_bdays:int, data_flags:string[],
     *               actionable:int, confirmed_at_approx:bool}
     */
    public static function resolve(array $row, array $contacts, DateTimeImmutable $now): array {
        $utc = new DateTimeZone('UTC');
        $now = $now->setTimezone($utc);

        $out = [
            'state'               => '',
            'reason'              => null,
            'due_at'              => null,
            'overdue_bdays'       => 0,
            'data_flags'          => [],
            'actionable'          => 0,
            'confirmed_at_approx' => false,
        ];

        $status = (string) ($row['order_status'] ?? '');
        $trx    = trim((string) ($row['trx'] ?? ''));

        // 1. CLOSED
        if (!in_array($status, self::OPEN_STATUSES, true)) {
            return self::finish($out, 'CLOSED', 'order_' . ($status !== '' ? $status : 'unknown'));
        }
        if ($trx === self::TRANSFER_TRX) {
            return self::finish($out, 'CLOSED', 'transfer_order');
        }

        // 2. MISSING_TRX
        if ($trx === '') {
            return self::finish($out, 'MISSING_TRX', 'no_trx');
        }

        // 3. SHIPPED_NOT_CLOSED
        $remaining = (int) ($row['qty_ordered'] ?? 0) - (int) ($row['qty_shipped'] ?? 0);
        if ($remaining <= 0) {
            $missing_po = !empty($row['is_drop_ship']) && trim((string) ($row['po_number'] ?? '')) === '';
            return self::finish($out, 'SHIPPED_NOT_CLOSED', $missing_po ? 'missing_po' : 'order_open');
        }

        // 4. EXCEPTION
        $ls = (string) ($row['logistics_status'] ?? '');
        if ($ls === '') $ls = 'in_stock';
        if ($ls === 'replaced' || $ls === 'obsolete') {
            return self::finish($out, 'EXCEPTION', $ls);
        }
        if ($ls !== 'in_stock' && $ls !== 'backordered') {
            return self::finish($out, 'EXCEPTION', 'unknown_logistics_status');
        }

        $eta       = self::parse_date($row['eta'] ?? null);
        $confirmed = self::parse_utc($row['confirmed_at'] ?? null);
        $approx    = ($row['confirmed_at_source'] ?? '') === 'backfill_approx';

        // due_at (§5.1)
        $due            = null;
        $uses_confirmed = false;
        if ($ls === 'backordered') {
            if ($eta) {
                $due         = self::end_of_day($eta);
                $reason_base = 'eta_passed';
            } else {
                $uses_confirmed = true;
                $reason_base    = 'no_eta';
            }
        } else { // in_stock
            if ($eta && self::end_of_day($eta) > $now) {
                $due                 = self::end_of_day($eta);
                $out['data_flags'][] = 'instock_future_date';
                $reason_base         = 'eta_passed';
            } elseif ($confirmed) {
                $due            = self::end_of_business_day($confirmed);
                $uses_confirmed = true;
                $reason_base    = 'not_shipped_same_day';
            } else {
                // TRX present but never confirmed — cannot happen through the projector; fail safe.
                return self::finish($out, 'ON_TRACK', 'awaiting_confirmation');
            }
        }

        if ($uses_confirmed && $approx) {
            $out['confirmed_at_approx'] = true;
            $out['data_flags'][]        = 'confirmed_at_approx';
        }
        $out['due_at'] = $due ? $due->setTimezone($utc)->format('Y-m-d H:i:s') : null;

        $overdue = ($due === null) || ($now > $due);

        // 5. ON_TRACK
        if (!$overdue) {
            return self::finish($out, 'ON_TRACK', $ls === 'backordered' || $eta ? 'eta_future' : 'ships_today');
        }

        $from = $due ?? $confirmed;
        $out['overdue_bdays'] = $from ? self::bdays_between($from, $now) : 0;

        // trigger_email = latest email at/after expiry
        $expiry  = $due ?? $confirmed;
        $trigger = null;
        $calls   = [];
        foreach ($contacts as $c) {
            $at = self::parse_utc($c['created_at'] ?? null);
            if (!$at) continue;
            if (($c['action'] ?? '') === 'email_logged') {
                if (($expiry === null || $at >= $expiry) && ($trigger === null || $at > $trigger)) {
                    $trigger = $at;
                }
            } elseif (($c['action'] ?? '') === 'call_logged') {
                $calls[] = $at;
            }
        }

        $called_after = false;
        if ($trigger) {
            foreach ($calls as $at) {
                if ($at > $trigger) { $called_after = true; break; }
            }
        }

        // 6. CALL_REQUIRED
        if ($trigger && !$called_after && $now >= self::add_bdays($trigger, 1)) {
            return self::finish($out, 'CALL_REQUIRED', 'no_response_1bd');
        }

        // 7. NEEDS_ETA
        if ($trigger && $called_after) {
            $reason = 'called_awaiting_eta';
        } elseif ($trigger) {
            $reason = 'emailed_awaiting_response';
        } else {
            $reason = $reason_base;
        }
        return self::finish($out, 'NEEDS_ETA', $reason);
    }

    private static function finish(array $out, string $state, ?string $reason): array {
        $out['state']      = $state;
        $out['reason']     = $reason;
        $out['actionable'] = in_array($state, self::ACTIONABLE, true) ? 1 : 0;
        $out['data_flags'] = array_values(array_unique($out['data_flags']));
        return $out;
    }

    /* -------------------- Business-day arithmetic (Mon–Fri ET, no holidays — O2) -------------------- */

    private static function et(): DateTimeZone { return new DateTimeZone(self::TZ); }

    private static function is_weekend(DateTimeImmutable $t): bool {
        return (int) $t->format('N') >= 6;
    }

    /** Moves forward n business days keeping time of day; weekend starts roll to Monday 00:00 first. */
    public static function add_bdays(DateTimeImmutable $t, int $n): DateTimeImmutable {
        $t = $t->setTimezone(self::et());
        if (self::is_weekend($t)) {
            while (self::is_weekend($t)) $t = $t->modify('+1 day');
            $t = $t->setTime(0, 0, 0);
        }
        for ($i = 0; $i < $n; $i++) {
            $t = $t->modify('+1 day');
            while (self::is_weekend($t)) $t = $t->modify('+1 day');
        }
        return $t;
    }

    /** 23:59:59 ET on the business day of $t (weekends roll to Monday). */
    public static function end_of_business_day(DateTimeImmutable $t): DateTimeImmutable {
        $t = $t->setTimezone(self::et());
        while (self::is_weekend($t)) $t = $t->modify('+1 day');
        return $t->setTime(23, 59, 59);
    }

    /** Count of business days d (ET dates) with date($from) < d <= date($to). */
    public static function bdays_between(DateTimeImmutable $from, DateTimeImmutable $to): int {
        $d   = $from->setTimezone(self::et())->setTime(12, 0, 0);
        $end = $to->setTimezone(self::et())->format('Y-m-d');
        $n   = 0;
        for ($guard = 0; $guard < 3660; $guard++) {
            $d = $d->modify('+1 day');
            if ($d->format('Y-m-d') > $end) break;
            if (!self::is_weekend($d)) $n++;
        }
        return $n;
    }

    /**
     * Cutoff timestamp (in UTC) for orders coming due within $bdays business days.
     * 23:59:59 ET of the business day $bdays business days after $now, converted to UTC.
     * Weekends roll back to Friday so that Saturday covers through Tuesday EOD (matching Friday).
     */
    public static function coming_due_cutoff(DateTimeImmutable $now, int $bdays = self::COMING_DUE_BDAYS): DateTimeImmutable {
        $now_et = $now->setTimezone(self::et());
        if (self::is_weekend($now_et)) {
            while (self::is_weekend($now_et)) {
                $now_et = $now_et->modify('-1 day');
            }
        }
        return self::end_of_business_day(self::add_bdays($now_et, $bdays))->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Whether a row is ON_TRACK, has a due_at timestamp, and is due on or before the cutoff.
     */
    public static function is_coming_due(array $row, DateTimeImmutable $now): bool {
        if (($row['state'] ?? '') !== 'ON_TRACK') {
            return false;
        }
        $raw = $row['due_at'] ?? null;
        if ($raw === null || $raw === '' || $raw === '0000-00-00 00:00:00') {
            return false;
        }
        $due = $raw instanceof DateTimeImmutable
            ? $raw->setTimezone(new DateTimeZone('UTC'))
            : self::parse_utc($raw);
        if (!$due) {
            return false;
        }
        $cutoff = self::coming_due_cutoff($now, self::COMING_DUE_BDAYS);
        return $due <= $cutoff;
    }

    private static function end_of_day(DateTimeImmutable $date_et): DateTimeImmutable {
        return $date_et->setTime(23, 59, 59);
    }

    private static function parse_date($v): ?DateTimeImmutable {
        $v = trim((string) $v);
        if ($v === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return null;
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v, self::et());
        return $d ?: null;
    }

    private static function parse_utc($v): ?DateTimeImmutable {
        $v = trim((string) $v);
        if ($v === '' || $v === '0000-00-00 00:00:00') return null;
        $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $v, new DateTimeZone('UTC'));
        return $d ?: null;
    }
}
