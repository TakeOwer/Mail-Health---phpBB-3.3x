<?php
/**
 *
 * Mail Health. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\mailhealth\service;

/**
 * Parses a raw e-mail message and, when it is a delivery status
 * notification, extracts the failed recipient and the reason.
 *
 * The mailbox it reads is often the board's everyday address, full of
 * ordinary mail. So before anything else the message has to look like a
 * bounce - sent by a mail system, or carrying a delivery report - and only
 * then is it examined. An ordinary e-mail that happens to contain an address
 * and something like "4.97.1" must never be mistaken for one.
 *
 * Strategy, in order of reliability:
 *   1. RFC 3464 message/delivery-status part (Final-Recipient + Status)
 *   2. X-Failed-Recipients header (Exim and others)
 *   3. Human readable body of a message that is clearly a bounce
 *
 * The parser never decides what to do with the result. It only reports.
 */
class dsn_parser
{
	const TYPE_UNKNOWN	= 0;
	const TYPE_SOFT		= 1;
	const TYPE_HARD		= 2;

	/**
	 * Enhanced status code, RFC 3463: class 2/4/5, subject 0-7, detail 0-999.
	 * Anything else ("4.97.1", an IP address, a version number) is not one.
	 */
	const STATUS_PATTERN = '[245]\.[0-7]\.\d{1,3}';

	/** Failure codes only: what the text strategies may pick up */
	const FAILURE_PATTERN = '[45]\.[0-7]\.\d{1,3}';

	/** Returned by parse_delivery_status() for success reports */
	const SUCCESS = 'success';

	/**
	 * @param string $raw Full raw message, headers included
	 * @return array|false ['email' => string, 'type' => int, 'status' => string, 'diagnostic' => string]
	 */
	public function parse($raw)
	{
		$raw = (string) $raw;
		$headers = $this->headers($raw);

		$has_report = (bool) preg_match('/report-type\s*=\s*"?delivery-status/i', $raw)
			|| stripos($raw, 'message/delivery-status') !== false;

		if (!$has_report && !$this->looks_like_bounce($headers))
		{
			return false;
		}

		$result = $has_report ? $this->parse_delivery_status($raw) : false;

		// A report saying the message WAS delivered: nothing to do, and the
		// text strategies must not get a chance to misread it.
		if ($result === self::SUCCESS)
		{
			return false;
		}

		if ($result === false)
		{
			$result = $this->parse_failed_recipients_header($raw, $headers);
		}

		if ($result === false)
		{
			$result = $this->parse_body_fallback($raw, $headers);
		}

		if ($result === false || !$this->is_valid_email($result['email']) || $this->is_system_address($result['email']))
		{
			return false;
		}

		$result['email'] = strtolower($result['email']);

		return $result;
	}

	/**
	 * Decides from the header block alone whether a message is worth
	 * downloading. Ordinary mail - the bulk of an everyday mailbox - is
	 * turned down here and its body, attachments included, never fetched.
	 *
	 * @param string $header_block
	 * @return bool
	 */
	public function is_candidate($header_block)
	{
		$headers = $this->headers((string) $header_block . "\r\n\r\n");

		if (preg_match('/^Content-Type:.*report-type\s*=\s*"?delivery-status/mi', $headers))
		{
			return true;
		}

		return $this->looks_like_bounce($headers);
	}

	/**
	 * Header block only, unfolded, for the checks that must not look at the
	 * body (where an ordinary message can say anything).
	 */
	protected function headers($raw)
	{
		$end = strpos($raw, "\r\n\r\n");
		$end = ($end === false) ? strpos($raw, "\n\n") : $end;
		$block = ($end === false) ? $raw : substr($raw, 0, $end);

		return preg_replace('/\r?\n[ \t]+/', ' ', $block);
	}

	/**
	 * Is this message sent by a mail system about a failed delivery?
	 * Decided on headers only.
	 */
	protected function looks_like_bounce($headers)
	{
		if (preg_match('/^X-Failed-Recipients:/mi', $headers))
		{
			return true;
		}

		if (preg_match('/^From:.*\b(mailer-daemon|postmaster|mail delivery (sub)?system|mail delivery service)\b/mi', $headers))
		{
			return true;
		}

		$subjects = [
			'undeliver', 'delivery status notification', 'delivery failure', 'delivery has failed',
			'mail delivery failed', 'returned mail', 'failure notice', 'non recapitabile',
			'mancato recapito', 'impossibile recapitare', 'messaggio non consegnato', 'notifica sullo stato',
			'unzustellbar', 'non remis', 'no se puede entregar',
		];

		if (preg_match('/^Subject:(.*)$/mi', $headers, $m))
		{
			$subject = strtolower($m[1]);

			foreach ($subjects as $needle)
			{
				if (strpos($subject, $needle) !== false)
				{
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * RFC 3464: the machine readable part. This is what well behaved
	 * mail servers send and it covers the large majority of bounces.
	 */
	protected function parse_delivery_status($raw)
	{
		$email = '';
		$status = '';
		$diagnostic = '';

		if (preg_match('/^Final-Recipient:\s*[^;]*;\s*(.+)$/mi', $raw, $m))
		{
			$email = $this->clean_address($m[1]);
		}
		else if (preg_match('/^Original-Recipient:\s*[^;]*;\s*(.+)$/mi', $raw, $m))
		{
			$email = $this->clean_address($m[1]);
		}

		if ($email === '')
		{
			return false;
		}

		if (preg_match('/^Status:\s*(' . self::STATUS_PATTERN . ')/mi', $raw, $m))
		{
			$status = $m[1];
		}

		if (preg_match('/^Diagnostic-Code:\s*(.+)$/mi', $raw, $m))
		{
			$diagnostic = trim($m[1]);
		}

		// "delayed" is a warning that delivery is still being tried, and
		// "delivered"/"relayed"/"expanded" are success reports.
		if (preg_match('/^Action:\s*(\w+)/mi', $raw, $m))
		{
			$action = strtolower($m[1]);

			if (in_array($action, ['delivered', 'relayed', 'expanded'], true))
			{
				return self::SUCCESS;
			}

			if ($action === 'delayed')
			{
				return [
					'email'			=> $email,
					'type'			=> self::TYPE_SOFT,
					'status'		=> $status !== '' ? $status : '4.0.0',
					'diagnostic'	=> $diagnostic,
				];
			}
		}

		return [
			'email'			=> $email,
			'type'			=> $this->type_from_status($status, $diagnostic),
			'status'		=> $status,
			'diagnostic'	=> $diagnostic,
		];
	}

	/**
	 * Exim and a few others expose the address in a header. The status, if
	 * any, is read from the text.
	 */
	protected function parse_failed_recipients_header($raw, $headers)
	{
		if (!preg_match('/^X-Failed-Recipients:\s*(.+)$/mi', $headers, $m))
		{
			return false;
		}

		$addresses = explode(',', $m[1]);
		$email = $this->clean_address($addresses[0]);

		if ($email === '')
		{
			return false;
		}

		$status = preg_match('/\b(' . self::FAILURE_PATTERN . ')\b/', $raw, $s) ? $s[1] : '';

		return [
			'email'			=> $email,
			'type'			=> $this->type_from_status($status, $raw),
			'status'		=> $status,
			'diagnostic'	=> '',
		];
	}

	/**
	 * Last resort, only for messages already recognised as bounces by their
	 * headers: a failure code, or failing that a wording that clearly says
	 * the address does not exist, plus the address in the text.
	 */
	protected function parse_body_fallback($raw, $headers)
	{
		$status = preg_match('/\b(' . self::FAILURE_PATTERN . ')\b/', $raw, $status_match) ? $status_match[1] : '';

		// No code at all: accept the message only if its words say the
		// address is gone. Anything vaguer is left alone.
		if ($status === '' && $this->type_from_status('', $raw) !== self::TYPE_HARD)
		{
			return false;
		}

		if (!preg_match_all('/[<\s:]([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})[>\s,;]/i', $raw, $m))
		{
			return false;
		}

		// The first address that is not the reporting mail system itself
		foreach ($m[1] as $candidate)
		{
			$candidate = $this->clean_address($candidate);

			if ($candidate !== '' && !$this->is_system_address($candidate))
			{
				return [
					'email'			=> $candidate,
					'type'			=> $this->type_from_status($status, $raw),
					'status'		=> $status,
					'diagnostic'	=> '',
				];
			}
		}

		return false;
	}

	/**
	 * Plain language reason for a bounce, from its status code and, when the
	 * code is generic, from what the server wrote. "Temporary" tells the
	 * administrator what Mail Health will do; this tells him what happened.
	 *
	 * @param string $status     Enhanced status code, may be empty
	 * @param string $diagnostic Diagnostic-Code, may be empty
	 * @return string Language key
	 */
	public function describe($status, $diagnostic)
	{
		$status = trim((string) $status);
		$text = strtolower((string) $diagnostic);

		$codes = [
			'4.2.2' => 'MAILHEALTH_WHY_FULL',
			'5.2.2' => 'MAILHEALTH_WHY_FULL',
			'5.3.4' => 'MAILHEALTH_WHY_TOO_BIG',
			'5.2.3' => 'MAILHEALTH_WHY_TOO_BIG',
			'5.1.1' => 'MAILHEALTH_WHY_NO_MAILBOX',
			'5.1.6' => 'MAILHEALTH_WHY_NO_MAILBOX',
			'5.1.2' => 'MAILHEALTH_WHY_NO_DOMAIN',
			'5.1.10' => 'MAILHEALTH_WHY_NO_MAILBOX',
			'5.2.1' => 'MAILHEALTH_WHY_DISABLED',
			'4.2.1' => 'MAILHEALTH_WHY_DISABLED_TEMP',
			'4.3.2' => 'MAILHEALTH_WHY_SERVER_BUSY',
			'4.4.1' => 'MAILHEALTH_WHY_UNREACHABLE',
			'4.4.2' => 'MAILHEALTH_WHY_UNREACHABLE',
			'4.4.7' => 'MAILHEALTH_WHY_EXPIRED',
			'5.4.4' => 'MAILHEALTH_WHY_DNS',
			'5.4.1' => 'MAILHEALTH_WHY_DNS',
			'5.7.1' => 'MAILHEALTH_WHY_REJECTED',
			'5.7.26' => 'MAILHEALTH_WHY_AUTH',
			'4.7.0' => 'MAILHEALTH_WHY_ANTISPAM',
			'4.7.1' => 'MAILHEALTH_WHY_ANTISPAM',
			'5.7.0' => 'MAILHEALTH_WHY_ANTISPAM',
		];

		if (isset($codes[$status]))
		{
			return $codes[$status];
		}

		// Generic or missing code: the server's own words usually say it.
		$patterns = [
			'MAILHEALTH_WHY_FULL'		=> ['quota', 'storage space', 'mailbox full', 'casella piena', 'over quota', 'insufficient system storage'],
			'MAILHEALTH_WHY_NO_MAILBOX'	=> ['user unknown', 'no such user', 'does not exist', 'recipient not found', 'unknown recipient', 'destinatario sconosciuto', 'indirizzo inesistente', 'utente sconosciuto', 'casella inesistente', 'unrouteable address'],
			'MAILHEALTH_WHY_DISABLED'	=> ['disabled', 'account has been suspended', 'inactive', 'disattivat'],
			'MAILHEALTH_WHY_DNS'		=> ['dns', 'host or domain name not found', 'no mx record', 'nxdomain'],
			'MAILHEALTH_WHY_ANTISPAM'	=> ['spam', 'blacklist', 'blocked', 'policy', 'reputation', 'rate limit'],
			'MAILHEALTH_WHY_TOO_BIG'	=> ['message too large', 'size limit', 'exceeds maximum'],
			'MAILHEALTH_WHY_EXPIRED'	=> ['retry time', 'giving up', 'delivery time expired'],
		];

		foreach ($patterns as $key => $needles)
		{
			foreach ($needles as $needle)
			{
				if ($text !== '' && strpos($text, $needle) !== false)
				{
					return $key;
				}
			}
		}

		// A code that is not a valid enhanced status (an old record, or a
		// number picked out of a message by an earlier version) says nothing
		// at all: better to admit it than to guess from its first digit.
		if (!preg_match('/^' . self::STATUS_PATTERN . '$/', $status))
		{
			return 'MAILHEALTH_WHY_UNKNOWN';
		}

		return ((int) substr($status, 0, 1) === 5) ? 'MAILHEALTH_WHY_PERMANENT' : 'MAILHEALTH_WHY_TEMPORARY';
	}

	/**
	 * Decide between hard and soft.
	 *
	 * The SMTP class digit is authoritative when present: 5 is permanent,
	 * 4 is temporary. The known exceptions lean towards soft - a false soft
	 * costs nothing, a false hard switches off a legitimate member's e-mail.
	 */
	protected function type_from_status($status, $text)
	{
		$lower = strtolower((string) $text);

		if ($status !== '')
		{
			$class = (int) substr($status, 0, 1);

			if ($class === 5)
			{
				// Mailbox full: reported as permanent by some servers, almost
				// always temporary in practice.
				if (in_array($status, ['5.2.2', '5.3.4'], true))
				{
					return self::TYPE_SOFT;
				}

				// Routing and DNS failures (5.4.x, or a DNS error in the text)
				// are very often a hiccup on the sending side, not a dead
				// address. If the address really is dead, the soft bounces
				// keep coming and the soft limit catches it.
				if (strpos($status, '5.4.') === 0 || $this->mentions_dns($lower))
				{
					return self::TYPE_SOFT;
				}

				return self::TYPE_HARD;
			}

			if ($class === 4)
			{
				return self::TYPE_SOFT;
			}
		}

		if ($this->mentions_dns($lower))
		{
			return self::TYPE_SOFT;
		}

		$hard_patterns = [
			'user unknown',
			'no such user',
			'recipient not found',
			'mailbox unavailable',
			'address rejected',
			'does not exist',
			'unrouteable address',
			'account has been disabled',
			'destinatario sconosciuto',
			'indirizzo inesistente',
			'utente sconosciuto',
			'casella inesistente',
		];

		foreach ($hard_patterns as $pattern)
		{
			if (strpos($lower, $pattern) !== false)
			{
				return self::TYPE_HARD;
			}
		}

		return self::TYPE_SOFT;
	}

	protected function mentions_dns($lower)
	{
		return (bool) preg_match('/\bdns\b|host or domain name not found|name service error|no mx record|temporary lookup failure|nxdomain/', $lower);
	}

	protected function is_system_address($email)
	{
		return (bool) preg_match('/^(mailer-daemon|postmaster|noreply|no-reply|bounce[s]?)@/i', (string) $email);
	}

	protected function clean_address($value)
	{
		$value = trim($value);
		$value = trim($value, "<>\"' \t\r\n");
		$value = preg_replace('/\s.*$/', '', $value);

		return (string) $value;
	}

	protected function is_valid_email($email)
	{
		return $email !== '' && (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
	}
}
