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
 * Reads the public DNS records that decide whether the board's mail is
 * trusted: SPF, DKIM, DMARC, plus MX for the bounce mailbox domain.
 *
 * Deliberately read-only and descriptive. It can tell that a record is
 * missing, duplicated or permissive; it cannot tell whether the IP the board
 * sends from is actually covered by SPF, because that depends on the path
 * the mail takes through the host - so it never claims more than it knows.
 */
class dns_check
{
	const OK	= 'ok';
	const WARN	= 'warn';
	const FAIL	= 'fail';

	/** Selectors tried when none is configured, most common first */
	const COMMON_SELECTORS = ['default', 'mail', 'dkim', 'google', 'selector1', 'selector2', 'k1', 's1', 's2', 'smtp', 'x'];

	/** @var \phpbb\config\config */
	protected $config;

	public function __construct(\phpbb\config\config $config)
	{
		$this->config = $config;
	}

	/**
	 * Administrators often paste the whole record name from their DNS panel
	 * ("x._domainkey.example.com.") instead of the selector alone ("x").
	 * Both are accepted: everything from "._domainkey" on is dropped, along
	 * with spaces and trailing dots.
	 */
	public static function normalize_selector($value)
	{
		$value = strtolower(trim((string) $value));
		$cut = strpos($value, '._domainkey');

		if ($cut !== false)
		{
			$value = substr($value, 0, $cut);
		}

		return trim($value, ". \t");
	}

	public function is_available()
	{
		return function_exists('dns_get_record');
	}

	/**
	 * Domain of the board's sending address.
	 */
	public function get_domain()
	{
		$email = (string) $this->config['board_email'];
		$at = strrpos($email, '@');

		return ($at === false) ? '' : strtolower(substr($email, $at + 1));
	}

	/**
	 * @return array Rows ['label', 'status', 'detail', 'hint'] (label and
	 *               hint are language keys; detail is raw data, or the
	 *               MAILHEALTH_DNS_NOT_FOUND key when a record is missing)
	 */
	public function run()
	{
		$rows = [];
		$domain = $this->get_domain();

		if (!$this->is_available())
		{
			$rows[] = ['MAILHEALTH_DNS_LOOKUP', self::FAIL, '', 'MAILHEALTH_DNS_UNAVAILABLE'];
			return $this->to_assoc($rows);
		}

		if ($domain === '')
		{
			$rows[] = ['MAILHEALTH_DNS_DOMAIN', self::FAIL, '', 'MAILHEALTH_DNS_NO_DOMAIN'];
			return $this->to_assoc($rows);
		}

		$rows[] = ['MAILHEALTH_DNS_DOMAIN', self::OK, $domain, ''];
		$rows[] = $this->check_spf($domain);
		$rows[] = $this->check_dkim($domain);
		$rows[] = $this->check_dmarc($domain);
		$rows[] = $this->check_mx();

		return $this->to_assoc($rows);
	}

	protected function check_spf($domain)
	{
		$records = array_values(array_filter($this->txt($domain), function ($txt) {
			return stripos($txt, 'v=spf1') === 0;
		}));

		if (empty($records))
		{
			return ['MAILHEALTH_DNS_SPF', self::FAIL, 'MAILHEALTH_DNS_NOT_FOUND', 'MAILHEALTH_DNS_SPF_MISSING'];
		}

		if (count($records) > 1)
		{
			// RFC 7208: more than one SPF record is a permanent error, the
			// receiving server must treat SPF as broken.
			return ['MAILHEALTH_DNS_SPF', self::FAIL, implode(' | ', $records), 'MAILHEALTH_DNS_SPF_MULTIPLE'];
		}

		$spf = $records[0];

		if (preg_match('/[+]all\b/i', $spf) || (preg_match('/\sall\b/i', $spf) && !preg_match('/[-~?]all\b/i', $spf)))
		{
			return ['MAILHEALTH_DNS_SPF', self::FAIL, $spf, 'MAILHEALTH_DNS_SPF_PLUSALL'];
		}

		if (preg_match('/\?all\b/i', $spf) || !preg_match('/[-~]all\b/i', $spf))
		{
			return ['MAILHEALTH_DNS_SPF', self::WARN, $spf, 'MAILHEALTH_DNS_SPF_NEUTRAL'];
		}

		return ['MAILHEALTH_DNS_SPF', self::OK, $spf, ''];
	}

	protected function check_dkim($domain)
	{
		$configured = self::normalize_selector((string) $this->config['mailhealth_dkim_selector']);
		$selectors = ($configured !== '') ? [$configured] : self::COMMON_SELECTORS;

		foreach ($selectors as $selector)
		{
			foreach ($this->txt($selector . '._domainkey.' . $domain) as $txt)
			{
				if (stripos($txt, 'p=') === false)
				{
					continue;
				}

				// An empty p= means the key has been revoked.
				if (preg_match('/(^|;)\s*p=\s*(;|$)/i', $txt))
				{
					return ['MAILHEALTH_DNS_DKIM', self::FAIL, $selector . ': ' . $this->shorten($txt), 'MAILHEALTH_DNS_DKIM_REVOKED'];
				}

				return ['MAILHEALTH_DNS_DKIM', self::OK, $selector . ': ' . $this->shorten($txt), ($configured === '') ? 'MAILHEALTH_DNS_DKIM_GUESSED' : ''];
			}
		}

		if ($configured !== '')
		{
			return ['MAILHEALTH_DNS_DKIM', self::FAIL, $configured, 'MAILHEALTH_DNS_DKIM_NOT_FOUND'];
		}

		return ['MAILHEALTH_DNS_DKIM', self::WARN, 'MAILHEALTH_DNS_NOT_FOUND', 'MAILHEALTH_DNS_DKIM_UNKNOWN'];
	}

	protected function check_dmarc($domain)
	{
		$records = array_values(array_filter($this->txt('_dmarc.' . $domain), function ($txt) {
			return stripos($txt, 'v=DMARC1') === 0;
		}));

		if (empty($records))
		{
			return ['MAILHEALTH_DNS_DMARC', self::WARN, 'MAILHEALTH_DNS_NOT_FOUND', 'MAILHEALTH_DNS_DMARC_MISSING'];
		}

		$dmarc = $records[0];

		if (!preg_match('/(^|;)\s*p\s*=\s*(none|quarantine|reject)/i', $dmarc, $m))
		{
			return ['MAILHEALTH_DNS_DMARC', self::FAIL, $dmarc, 'MAILHEALTH_DNS_DMARC_INVALID'];
		}

		if (strtolower($m[2]) === 'none')
		{
			// p=none is the recommended way to start, not a fault: the record
			// is there and working, it just does not enforce anything yet.
			return ['MAILHEALTH_DNS_DMARC', self::OK, $dmarc, 'MAILHEALTH_DNS_DMARC_NONE'];
		}

		return ['MAILHEALTH_DNS_DMARC', self::OK, $dmarc, ''];
	}

	/**
	 * The bounce mailbox must be able to receive mail at all.
	 */
	protected function check_mx()
	{
		$user = (string) $this->config['mailhealth_imap_user'];
		$at = strrpos($user, '@');
		$domain = ($at === false) ? $this->get_domain() : strtolower(substr($user, $at + 1));

		$records = @dns_get_record($domain, DNS_MX);

		if (empty($records))
		{
			return ['MAILHEALTH_DNS_MX', self::FAIL, $domain, 'MAILHEALTH_DNS_MX_MISSING'];
		}

		usort($records, function ($a, $b) {
			return (int) $a['pri'] - (int) $b['pri'];
		});

		$hosts = array_map(function ($r) {
			return $r['target'] . ' (' . (int) $r['pri'] . ')';
		}, $records);

		return ['MAILHEALTH_DNS_MX', self::OK, $domain . ': ' . implode(', ', $hosts), ''];
	}

	/**
	 * TXT records of a name, each one joined back from its 255 byte chunks.
	 *
	 * @return array
	 */
	protected function txt($name)
	{
		$records = @dns_get_record($name, DNS_TXT);
		$out = [];

		if (!is_array($records))
		{
			return $out;
		}

		foreach ($records as $record)
		{
			if (isset($record['entries']) && is_array($record['entries']))
			{
				$out[] = trim(implode('', $record['entries']));
			}
			else if (isset($record['txt']))
			{
				$out[] = trim($record['txt']);
			}
		}

		return $out;
	}

	protected function shorten($value, $max = 90)
	{
		return (strlen($value) > $max) ? substr($value, 0, $max) . '…' : $value;
	}

	protected function to_assoc(array $rows)
	{
		return array_map(function ($r) {
			return ['label' => $r[0], 'status' => $r[1], 'detail' => $r[2], 'hint' => $r[3]];
		}, $rows);
	}
}
